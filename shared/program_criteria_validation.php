<?php
/**
 * Program criteria validation: validates eligibility rules before saving,
 * detects conflicts, and produces a human-readable summary.
 */

/**
 * Validate program criteria data and return an array of error messages.
 * Empty array means valid. Both program types are validated identically -
 * application_required programs need criteria to decide who may apply, and
 * no_application_required programs need them to decide who is auto-enrolled.
 *
 * @param array $data The raw criteria data from the form
 * @return list<string>
 */
function validate_program_criteria(array $data): array
{
    $errors = [];

    $minAge = $data['min_age'] ?? '';
    $maxAge = $data['max_age'] ?? '';
    $incomeDir = $data['income_direction'] ?? 'none';
    $incomeAmount = $data['income_amount'] ?? '';
    $requiresPwd = $data['requires_pwd'] ?? false;
    $requiresSoloParent = $data['requires_solo_parent'] ?? false;
    $requiresOfw = $data['requires_ofw'] ?? false;
    $requiresIp = $data['requires_ip'] ?? false;
    $requiresOsy = $data['requires_osy'] ?? false;
    $requiresUnemployed = $data['requires_unemployed'] ?? false;
    $requiresEmployed = $data['requires_employed'] ?? false;
    $requiresStudent = $data['requires_student'] ?? false;

    // Numeric validation
    if ($minAge !== '' && !is_numeric($minAge)) {
        $errors[] = 'Minimum age must be a valid number.';
    }
    if ($maxAge !== '' && !is_numeric($maxAge)) {
        $errors[] = 'Maximum age must be a valid number.';
    }
    if ($incomeAmount !== '' && !is_numeric($incomeAmount)) {
        $errors[] = 'Income amount must be a valid number.';
    }

    // Age range validation
    if ($minAge !== '' && $maxAge !== '' && is_numeric($minAge) && is_numeric($maxAge)) {
        if ((int) $minAge > (int) $maxAge) {
            $errors[] = 'Minimum age (' . $minAge . ') cannot be greater than maximum age (' . $maxAge . ').';
        }
    }

    // Plausible age range
    if ($minAge !== '' && is_numeric($minAge) && (int) $minAge < 0) {
        $errors[] = 'Minimum age cannot be negative.';
    }
    if ($maxAge !== '' && is_numeric($maxAge) && (int) $maxAge > 150) {
        $errors[] = 'Maximum age (' . $maxAge . ') seems unrealistic (over 150).';
    }

    // Income validation
    if ($incomeDir !== 'none') {
        if ($incomeAmount === '') {
            $errors[] = 'Enter the monthly income amount, or select "No income condition".';
        } elseif (is_numeric($incomeAmount) && (float) $incomeAmount < 0) {
            $errors[] = 'Monthly income cannot be negative.';
        }
    }

    // Conflicting flags
    if ($requiresUnemployed && $requiresEmployed) {
        $errors[] = 'Cannot require both "Employed" and "Unemployed" at the same time.';
    }

    // At least one criterion check
    $hasAnyCriterion = $minAge !== '' || $maxAge !== ''
        || ($incomeDir !== 'none' && $incomeAmount !== '')
        || $requiresPwd || $requiresSoloParent || $requiresOfw
        || $requiresIp || $requiresOsy || $requiresUnemployed
        || $requiresEmployed || $requiresStudent;

    if (!$hasAnyCriterion) {
        $errors[] = 'Set at least one eligibility condition - otherwise every resident in the barangay would qualify.';
    }

    return $errors;
}

/**
 * Build a human-readable summary of the program criteria.
 */
function summarize_program_criteria(array $criteria): string
{
    $parts = [];

    // Age
    $minAge = $criteria['min_age'] ?? null;
    $maxAge = $criteria['max_age'] ?? null;
    if ($minAge !== null && $maxAge !== null) {
        $parts[] = "Age {$minAge}–{$maxAge}";
    } elseif ($minAge !== null) {
        $parts[] = "Age {$minAge}+";
    } elseif ($maxAge !== null) {
        $parts[] = "Age up to {$maxAge}";
    }

    // Income
    $maxIncome = $criteria['max_income'] ?? null;
    $minIncome = $criteria['min_income'] ?? null;
    if ($maxIncome !== null && $maxIncome !== '') {
        $parts[] = 'Monthly income up to ₱' . number_format((float) $maxIncome, 0);
    }
    if ($minIncome !== null && $minIncome !== '') {
        $parts[] = 'Monthly income at least ₱' . number_format((float) $minIncome, 0);
    }

    // Demographic flags
    $flagMap = [
        'requires_pwd'          => 'PWD',
        'requires_solo_parent'  => 'Solo parent',
        'requires_ofw'          => 'OFW',
        'requires_ip'           => 'Indigenous person',
        'requires_osy'          => 'Out-of-school youth',
        'requires_unemployed'   => 'Unemployed',
        'requires_employed'     => 'Employed',
        'requires_student'      => 'Student',
    ];

    foreach ($flagMap as $key => $label) {
        if (!empty($criteria[$key])) {
            $parts[] = $label;
        }
    }

    // Household size
    $minHousehold = $criteria['min_household_size'] ?? null;
    if ($minHousehold !== null) {
        $parts[] = "Household of {$minHousehold}+";
    }

    return $parts ? implode(' · ', $parts) : 'No conditions set';
}

/**
 * Build a detailed validation report for criteria, suitable for display
 * to the admin before saving.
 */
function validate_program_criteria_detailed(array $data): array
{
    $errors = validate_program_criteria($data);

    $warnings = [];

    $summary = summarize_program_criteria($data);

    // Warnings (non-blocking)
    $minAge = $data['min_age'] ?? '';
    $maxAge = $data['max_age'] ?? '';

    if ($minAge !== '' && is_numeric($minAge) && (int) $minAge < 0) {
        $warnings[] = 'Minimum age is negative - did you mean 0?';
    }

    if ($maxAge !== '' && is_numeric($maxAge) && (int) $maxAge > 120) {
        $warnings[] = 'Maximum age is over 120 - this is unusual for most programs.';
    }

    // Check for mutually exclusive flags
    if (!empty($data['requires_employed']) && !empty($data['requires_unemployed'])) {
        $warnings[] = 'Both "Employed" and "Unemployed" are checked. No one can be both at the same time.';
    }

    // Check for common oversights
    if ($minAge !== '' && is_numeric($minAge) && (int) $minAge >= 60
        && empty($data['requires_pwd']) && empty($data['requires_solo_parent'])
        && empty($data['requires_ofw']) && empty($data['requires_ip'])
        && empty($data['requires_osy']) && empty($data['requires_unemployed'])
        && empty($data['requires_employed']) && empty($data['requires_student'])) {
        $warnings[] = 'This targets residents aged ' . $minAge . '+ with no other restrictions. Make sure this is intentional.';
    }

    return [
        'errors'   => $errors,
        'warnings' => $warnings,
        'summary'  => $summary,
        'valid'    => empty($errors),
    ];
}
