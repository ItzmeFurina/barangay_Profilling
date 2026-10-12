-- Populates barangay_info with real data from PhilAtlas
-- (philatlas.com/luzon/ncr/manila/barangay-420.html), fetched 2026-07-20.
-- Data-only, safe to re-run - only touches info_id = 1.
-- In phpMyAdmin: barangay420 database, SQL tab, paste, Go.

UPDATE barangay_info
SET about_text = 'Barangay 420 is located in the Sampaloc district of Manila, within the National Capital Region. According to the 2020 census, the barangay is home to 3,134 residents - up from 2,811 in the 2015 count of roughly 643 households, an average of about 4 to 5 people per household. The population has grown steadily since 1990, when it stood at 2,763.'
WHERE info_id = 1;
