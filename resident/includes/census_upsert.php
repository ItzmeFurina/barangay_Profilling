<?php
require_once __DIR__ . '/../../shared/functions.php';

/**
 * Validates the household-address fields of a census submission.
 * Returns a list of error messages (empty when valid).
 */
function census_validate_household(array $h): array {
    $errors = [];
    if (($h['house_number'] ?? '') === '') {
        $errors[] = 'House / Building / Unit No. is required.';
    }
    if (($h['street'] ?? '') === '') {
        $errors[] = 'Street is required.';
    }
    if (($h['barangay'] ?? '') === '') {
        $errors[] = 'Barangay is required.';
    }
    if (($h['city'] ?? '') === '') {
        $errors[] = 'City is required.';
    }
    if (($h['monthly_income'] ?? '') !== '' && !is_numeric($h['monthly_income'])) {
        $errors[] = 'Estimated monthly household income must be a number.';
    } elseif (($h['monthly_income'] ?? '') !== '' && (float) $h['monthly_income'] < 0) {
        $errors[] = 'Estimated monthly household income cannot be negative.';
    }
    return $errors;
}

/**
 * Validates the submitted household members.
 * Returns ['errors' => [...], 'members' => [...cleanMembers...]].
 */
function census_validate_members(array $submittedMembers, string $headIndex): array {
    $errors = [];
    if (empty($submittedMembers)) {
        $errors[] = 'Add at least one household member.';
        return ['errors' => $errors, 'members' => []];
    }

    // The household head is submitted as an index into the members array.
    // Range-check it so an out-of-range/tampered value can't silently leave
    // the household with no head at all.
    $headIndex = trim($headIndex);
    if ($headIndex === '' || !array_key_exists((int) $headIndex, $submittedMembers)) {
        $errors[] = 'Select which household member is the head of the household.';
    }

    $cleanMembers = [];
    foreach ($submittedMembers as $i => $m) {
        $lastName  = normalize_person_name($m['last_name'] ?? '');
        $firstName = normalize_person_name($m['first_name'] ?? '');
        $middleName = trim($m['middle_name'] ?? '');
        $placeOfBirth = trim($m['place_of_birth'] ?? '');
        $citizenship = trim($m['citizenship'] ?? '');
        $birthdate = trim($m['birthdate'] ?? '');
        $sex       = trim($m['sex'] ?? '');
        $civilStatus = normalize_civil_status($m['civil_status'] ?? '');

        if ($lastName === '' || $firstName === '') {
            $errors[] = 'Every household member needs at least a last and first name.';
        }
        if ($placeOfBirth === '') {
            $errors[] = trim("Enter a place of birth for {$firstName} {$lastName}.");
        }
        if ($citizenship === '') {
            $errors[] = trim("Enter a citizenship for {$firstName} {$lastName}.");
        }
        $birthdateObj = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$birthdateObj || $birthdateObj > new DateTime()) {
            $errors[] = trim("Enter a valid date of birth for {$firstName} {$lastName}.");
        }
        if (!in_array($sex, ['Male', 'Female'], true)) {
            $errors[] = trim("Select a sex for {$firstName} {$lastName}.");
        }
        if ($civilStatus === '') {
            $errors[] = trim("Select a civil status for {$firstName} {$lastName}.");
        } elseif (!in_array($civilStatus, ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'], true)) {
            $errors[] = trim("Select a valid civil status for {$firstName} {$lastName}.");
        }

        $occupationRaw = $m['occupation'] ?? '';
        if (trim($occupationRaw) === '') {
            $errors[] = trim("Enter an occupation for {$firstName} {$lastName}, or type N/A if none.");
        }
        $occupation = normalize_occupation($occupationRaw) ?: null;
        $isUnemployed = isset($m['is_unemployed']);
        $isEmployed = isset($m['is_employed']);
        $isStudent = isset($m['is_student']);

        if (is_none_occupation($occupationRaw)) {
            $isUnemployed = true;
            $isEmployed = false;
        } elseif ($occupation !== null && $isUnemployed) {
            $errors[] = trim("{$firstName} {$lastName} has an occupation, so they cannot also be marked Unemployed.");
        } elseif ($occupation !== null && !$isEmployed) {
            $isEmployed = true;
        }
        if ($isEmployed && $occupation === null) {
            $errors[] = trim("{$firstName} {$lastName} is marked Employed - enter their occupation.");
        }

        $cleanMembers[] = [
            'last_name'         => $lastName,
            'first_name'        => $firstName,
            'middle_name'       => normalize_person_name($m['middle_name'] ?? '') ?: null,
            'suffix'            => normalize_suffix($m['suffix'] ?? '') ?: null,
            'place_of_birth'    => normalize_label($m['place_of_birth'] ?? '') ?: null,
            'birthdate'         => $birthdate ?: null,
            'sex'               => $sex ?: null,
            'civil_status'      => $civilStatus ?: null,
            'citizenship'       => normalize_label($m['citizenship'] ?? '') !== '' ? normalize_label($m['citizenship']) : 'Filipino',
            'occupation'        => $occupation,
            'is_pwd'            => isset($m['is_pwd']),
            'is_solo_parent'    => isset($m['is_solo_parent']),
            'is_ofw'            => isset($m['is_ofw']),
            'is_osy'            => isset($m['is_osy']),
            'is_ip'             => isset($m['is_ip']),
            'is_unemployed'     => $isUnemployed,
            'is_employed'       => $isEmployed,
            'is_student'        => $isStudent,
            'is_household_head' => ((string) $i === (string) $headIndex),
        ];
    }

    return ['errors' => $errors, 'members' => $cleanMembers];
}

/**
 * Creates / joins a household and upserts its members inside an already-open
 * transaction. The account-holder member is linked to $actorUserId.
 *
 * Returns ['household_id' => int, 'member_id' => ?int].
 */
function census_save(PDO $pdo, array $household, array $cleanMembers, int $actorUserId): array {
    $houseNumber = ($household['house_number'] ?? '') !== '' ? $household['house_number'] : null;
    $barangay    = ($household['barangay'] ?? '') !== '' ? $household['barangay'] : null;

    $stmt = $pdo->prepare(
        'SELECT household_id FROM households
         WHERE street = ? AND city = ? AND house_number <=> ? AND barangay <=> ?
         ORDER BY household_id LIMIT 1'
    );
    $stmt->execute([$household['street'], $household['city'], $houseNumber, $barangay]);
    $householdId = $stmt->fetchColumn();

    if (!$householdId) {
        $stmt = $pdo->prepare(
            'INSERT INTO households (house_number, street, barangay, city, old_address, no_of_members, monthly_income)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([
            $houseNumber,
            $household['street'],
            $barangay,
            $household['city'],
            ($household['old_address'] ?? '') ?: null,
            ($household['monthly_income'] ?? '') !== '' ? (float) $household['monthly_income'] : null,
        ]);
        $householdId = (int) $pdo->lastInsertId();
    } else {
        // The income field is optional and usually submitted blank. Only
        // overwrite an existing value when the form actually supplied one -
        // writing NULL here would silently disqualify the household from
        // means-tested programs (eligibility treats "no income on file" as
        // failing both the cap and the floor). Blank means "no change".
        if (($household['monthly_income'] ?? '') !== '') {
            $stmt = $pdo->prepare(
                'UPDATE households SET monthly_income = ? WHERE household_id = ?'
            );
            $stmt->execute([
                (float) $household['monthly_income'],
                $householdId,
            ]);
        }
    }

    $findMemberStmt = $pdo->prepare(
        'SELECT member_id, linked_user_id FROM household_members
         WHERE household_id = ? AND is_archived = FALSE
           AND last_name = ? AND first_name = ? AND birthdate <=> ? AND suffix <=> ?
           AND (? IS NULL OR middle_name IS NULL OR middle_name = ?)
         LIMIT 1'
    );
    // Fallback finders for corrected identity fields. Matching used to rely
    // on the exact (last, first, birthdate, suffix) tuple only, so fixing a
    // typo in a name - or a wrong birthdate - made the lookup miss and the
    // INSERT below created a second copy of the same person. Each fallback
    // is only accepted when it yields exactly one candidate (ambiguous
    // results are ignored, preserving the old insert-new behaviour), and
    // candidates already claimed by an earlier member in this same submission
    // are excluded.
    $fallbackStatements = [
        // surname / middle-name correction: first name + birthdate still match
        $pdo->prepare(
            'SELECT member_id, linked_user_id FROM household_members
             WHERE household_id = ? AND is_archived = FALSE
               AND first_name = ? AND birthdate <=> ?
             LIMIT 2'
        ),
        // birthdate correction: full name + suffix still match
        $pdo->prepare(
            'SELECT member_id, linked_user_id FROM household_members
             WHERE household_id = ? AND is_archived = FALSE
               AND last_name = ? AND first_name = ? AND suffix <=> ?
             LIMIT 2'
        ),
        // first-name correction: surname + birthdate still match
        $pdo->prepare(
            'SELECT member_id, linked_user_id FROM household_members
             WHERE household_id = ? AND is_archived = FALSE
               AND last_name = ? AND birthdate <=> ? AND suffix <=> ?
             LIMIT 2'
        ),
    ];
    // Name and birthdate columns are written by the UPDATE too, so a
    // fallback match actually applies the correction instead of only
    // refreshing the descriptive fields.
    $updateMemberStmt = $pdo->prepare(
        'UPDATE household_members
         SET last_name = ?, first_name = ?, middle_name = ?, suffix = ?, birthdate = ?,
             place_of_birth = ?, sex = ?, civil_status = ?, citizenship = ?,
             occupation = ?, is_household_head = ?, is_pwd = ?, is_solo_parent = ?, is_ofw = ?,
             is_osy = ?, is_ip = ?, is_unemployed = ?, is_employed = ?, is_student = ?
         WHERE member_id = ?'
    );

    $accountHolderMemberId = null;
    $takenMemberIds = [];
    foreach ($cleanMembers as $i => $m) {
        $findMemberStmt->execute([
            $householdId,
            $m['last_name'], $m['first_name'], $m['birthdate'], $m['suffix'],
            $m['middle_name'], $m['middle_name'],
        ]);
        $existingMember = $findMemberStmt->fetch();

        if (!$existingMember) {
            $fallbackParams = [
                [$householdId, $m['first_name'], $m['birthdate']],
                [$householdId, $m['last_name'], $m['first_name'], $m['suffix']],
                [$householdId, $m['last_name'], $m['birthdate'], $m['suffix']],
            ];
            foreach ($fallbackStatements as $fbIndex => $fbStmt) {
                $fbStmt->execute($fallbackParams[$fbIndex]);
                $candidates = $fbStmt->fetchAll();
                $free = array_filter(
                    $candidates,
                    static fn(array $c): bool => !isset($takenMemberIds[(int) $c['member_id']])
                );
                if (count($free) === 1) {
                    $existingMember = array_values($free)[0];
                    break;
                }
            }
        }

        if ($existingMember) {
            $existingMemberId = (int) $existingMember['member_id'];
            $takenMemberIds[$existingMemberId] = true;
            // Only claim the account-holder member when it is not already
            // owned by a different resident - otherwise two accounts would
            // end up sharing one household_members row and clobber each
            // other's contact/photo/profile data.
            $claimable = $existingMember['linked_user_id'] === null
                || (int) $existingMember['linked_user_id'] === $actorUserId;
            if ($i === 0 && $claimable) {
                $accountHolderMemberId = $existingMemberId;
                $pdo->prepare(
                    'UPDATE household_members SET linked_user_id = ?
                     WHERE member_id = ? AND (linked_user_id IS NULL OR linked_user_id = ?)'
                )->execute([$actorUserId, $existingMemberId, $actorUserId]);
            }
            if ($claimable) {
                $updateMemberStmt->execute([
                    $m['last_name'], $m['first_name'], $m['middle_name'], $m['suffix'], $m['birthdate'],
                    $m['place_of_birth'], $m['sex'], $m['civil_status'],
                    $m['citizenship'], $m['occupation'], $m['is_household_head'] ? 1 : 0,
                    $m['is_pwd'] ? 1 : 0, $m['is_solo_parent'] ? 1 : 0, $m['is_ofw'] ? 1 : 0,
                    $m['is_osy'] ? 1 : 0, $m['is_ip'] ? 1 : 0, $m['is_unemployed'] ? 1 : 0,
                    $m['is_employed'] ? 1 : 0, $m['is_student'] ? 1 : 0,
                    $existingMemberId,
                ]);
            }
            continue;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO household_members
                (household_id, last_name, first_name, middle_name, suffix, place_of_birth,
                 birthdate, sex, civil_status, citizenship, occupation, is_household_head,
                 is_pwd, is_solo_parent, is_ofw, is_osy, is_ip, is_unemployed, is_employed, is_student, linked_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $householdId,
            $m['last_name'], $m['first_name'], $m['middle_name'], $m['suffix'], $m['place_of_birth'],
            $m['birthdate'], $m['sex'], $m['civil_status'], $m['citizenship'], $m['occupation'],
            $m['is_household_head'] ? 1 : 0,
            $m['is_pwd'] ? 1 : 0, $m['is_solo_parent'] ? 1 : 0, $m['is_ofw'] ? 1 : 0,
            $m['is_osy'] ? 1 : 0, $m['is_ip'] ? 1 : 0, $m['is_unemployed'] ? 1 : 0,
            $m['is_employed'] ? 1 : 0, $m['is_student'] ? 1 : 0,
            $i === 0 ? $actorUserId : null,
        ]);
        if ($i === 0) {
            $accountHolderMemberId = (int) $pdo->lastInsertId();
        }
    }

    // The account may have been linked to member rows in a household it
    // has just left (an address change re-matches to a different/new
    // household, and nothing else ever clears the old row). A stale link
    // would let an admin archiving that leftover "duplicate" row archive
    // this account too - so release every link except the one claimed
    // above. member_id <> 0 matches all rows when nothing was claimed.
    // Runs inside the caller's open transaction.
    $pdo->prepare(
        'UPDATE household_members
         SET linked_user_id = NULL
         WHERE linked_user_id = ? AND member_id <> ?'
    )->execute([$actorUserId, $accountHolderMemberId ?? 0]);

    $pdo->prepare(
        'UPDATE households SET no_of_members = (SELECT COUNT(*) FROM household_members WHERE household_id = ? AND is_archived = FALSE) WHERE household_id = ?'
    )->execute([$householdId, $householdId]);

    return ['household_id' => (int) $householdId, 'member_id' => $accountHolderMemberId !== null ? (int) $accountHolderMemberId : null];
}