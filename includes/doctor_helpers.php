<?php
/**
 * MedPulse Hospital System — Clinical Doctor Credential & Title Helpers
 * 
 * Auto-computes real-world medical honorific prefixes based on clinical designation,
 * military ranks, and post-graduate medical qualifications.
 */

if (!function_exists('cleanDoctorBaseName')) {
    /**
     * Extracts the pure base legal name of a doctor, stripping all leading academic,
     * clinical, and honorary/military prefixes to prevent duplicate title collisions (e.g. "Dr. Dr.").
     */
    function cleanDoctorBaseName(?string $rawName): string
    {
        if ($rawName === null || trim($rawName) === '') {
            return 'Physician';
        }

        $clean = trim($rawName);

        // Regular expression matching leading honorifics/military ranks/prefixes:
        // Col. (Retd.), Lt. Col. (Retd.), Brig. Gen. (Retd.), Major (Retd.),
        // Prof., Professor, Assoc. Prof., Asst. Prof., Dr., Dr, Doctor
        $prefixPattern = '/^(?:(?:Col\.|Lt\.\s*Col\.|Brig\.\s*Gen\.|Major)\s*(?:\(Retd\.?\))?\s*)*(?:(?:Assoc\.|Associate)\s+(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Asst\.|Assistant)\s+(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Dr\.?|Doctor)\s*)+/i';

        $clean = preg_replace($prefixPattern, '', $clean);
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        return !empty($clean) ? $clean : 'Physician';
    }
}

if (!function_exists('computeDoctorHonorificPrefix')) {
    /**
     * Determines the standard clinical honorific prefix according to academic/clinical rank,
     * optionally prepended with military commission honorifics.
     */
    function computeDoctorHonorificPrefix(?string $designation, ?string $militaryRank = null): string
    {
        $desig = trim((string)$designation);
        $prefix = 'Dr.';

        if (preg_match('/assoc/i', $desig)) {
            $prefix = 'Assoc. Prof. Dr.';
        } elseif (preg_match('/asst|assist/i', $desig)) {
            $prefix = 'Asst. Prof. Dr.';
        } elseif (preg_match('/prof/i', $desig)) {
            $prefix = 'Prof. Dr.';
        } else {
            // Consultant, Senior Consultant, Junior Consultant, Resident Physician, Medical Officer, etc.
            $prefix = 'Dr.';
        }

        $mil = trim((string)$militaryRank);
        if (!empty($mil) && strcasecmp($mil, 'None') !== 0) {
            $prefix = $mil . ' ' . $prefix;
        }

        return $prefix;
    }
}

if (!function_exists('formatDoctorTitle')) {
    /**
     * Returns the formatted professional title and base name:
     * e.g. "Prof. Dr. Minhazul Islam Alvi" or "Col. (Retd.) Prof. Dr. Minhazul Islam Alvi".
     */
    function formatDoctorTitle(?string $rawName, ?string $designation = null, ?string $militaryRank = null): string
    {
        $baseName = cleanDoctorBaseName($rawName);
        $prefix = computeDoctorHonorificPrefix($designation, $militaryRank);

        return trim($prefix . ' ' . $baseName);
    }
}

if (!function_exists('formatDoctorFullIdentity')) {
    /**
     * Returns the full clinical title with degrees/certifications:
     * e.g. "Prof. Dr. Minhazul Islam Alvi, MBBS, FCPS (Surgery)".
     */
    function formatDoctorFullIdentity(
        ?string $rawName,
        ?string $designation = null,
        ?string $militaryRank = null,
        ?string $qualifications = null
    ): string {
        $title = formatDoctorTitle($rawName, $designation, $militaryRank);
        $qual = trim((string)$qualifications);

        if (!empty($qual)) {
            return $title . ', ' . $qual;
        }

        return $title;
    }
}

if (!function_exists('formatDoctorAvatarInitials')) {
    /**
     * Computes clean 2-letter avatar initials strictly from the pure base name.
     */
    function formatDoctorAvatarInitials(?string $rawName): string
    {
        $base = cleanDoctorBaseName($rawName);
        $parts = preg_split('/\s+/', $base);

        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
        }

        return strtoupper(substr($base, 0, 2));
    }
}
