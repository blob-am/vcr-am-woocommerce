<?php

declare(strict_types=1);

namespace BlobSolutions\WooCommerceVcrAm\Refund;

/**
 * Strips PII from the WC refund-reason free-text before it leaves the
 * merchant's site for VCR.AM / SRC. Two redaction layers + length cap.
 *
 * The WC refund-reason field is an unstructured admin textarea. Admins
 * routinely paste:
 *
 *   - Customer email addresses (from a support thread)
 *   - Customer phone numbers
 *   - Quoted dispute text from chat
 *   - Internal notes that the SRC has no need for
 *
 * GDPR Art 5(1)(c) (data minimisation) calls for transmitting *only*
 * what's necessary for the processing purpose. SRC needs a categorical
 * reason ({@see RefundReasonMapper} maps the WC reason to an enum) plus
 * an optional short note. They do NOT need free-form PII.
 *
 * Strategy:
 *
 *   1. Replace email-shaped substrings with `[EMAIL_REDACTED]`.
 *   2. Replace phone-shaped substrings with `[PHONE_REDACTED]`. The
 *      regex is intentionally generous (anything that LOOKS like a
 *      phone number — runs of 7+ digits, with optional spaces / dashes
 *      / parens / leading +) — false positives only convert order
 *      numbers to a placeholder, which is harmless.
 *   3. Trim to a short cap (default 200 chars). Anything longer is
 *      almost certainly a copy-pasted conversation, not a categorical
 *      reason. The truncation marker (`…`) tells the audit trail
 *      something was elided.
 *
 * The output is what we send to SRC as `reasonNote`. Empty input
 * (after trim) returns null so the caller knows to omit the field
 * rather than send an empty string.
 *
 * Filterable via:
 *   - `vcr_refund_reason_max_length` (int) — change the length cap.
 *   - `vcr_refund_reason_sanitised`  (string|null, original) — final
 *     hook for stores that want to substitute their own redactor or
 *     a localised placeholder vocabulary.
 */
class RefundReasonSanitizer
{
    public const DEFAULT_MAX_LENGTH = 200;

    /**
     * Conservative email regex — matches RFC-5321 "happy path" addresses.
     * Not RFC-perfect (which would accept quoted local parts and
     * commented domains) but covers the 99% case admins actually paste.
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\\-]+@[A-Za-z0-9.\\-]+\\.[A-Za-z]{2,}/';

    /**
     * Phone regex — anything that has 7+ digits with optional separator
     * characters. International prefix (+), spaces, dashes, parens are
     * all swallowed. Won't match numbers shorter than 7 digits to avoid
     * eating order numbers / SKU numerics.
     */
    private const PHONE_PATTERN = '/(?:\\+?\\d[\\d\\s().\\-]{6,}\\d)/';

    /**
     * Sanitise a raw WC refund reason. Returns null when the result is
     * empty (so callers can pass it straight to a nullable SDK field).
     */
    public function sanitize(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $maxLengthRaw = apply_filters('vcr_refund_reason_max_length', self::DEFAULT_MAX_LENGTH);
        $maxLength = is_int($maxLengthRaw) && $maxLengthRaw > 0 ? $maxLengthRaw : self::DEFAULT_MAX_LENGTH;

        $redacted = preg_replace(self::EMAIL_PATTERN, '[EMAIL_REDACTED]', $trimmed);
        $redacted = is_string($redacted) ? $redacted : $trimmed;

        $redacted = preg_replace(self::PHONE_PATTERN, '[PHONE_REDACTED]', $redacted);
        $redacted = is_string($redacted) ? $redacted : $trimmed;

        // Length cap. Use mb_substr so a multibyte trim doesn't slice
        // through a UTF-8 byte boundary (admins write reasons in
        // Armenian / Russian / Cyrillic too).
        if (mb_strlen($redacted, 'UTF-8') > $maxLength) {
            $redacted = mb_substr($redacted, 0, $maxLength, 'UTF-8') . '…';
        }

        $final = apply_filters('vcr_refund_reason_sanitised', $redacted, $raw);
        if ($final === null) {
            return null;
        }
        if (! is_string($final)) {
            return $redacted;
        }

        $final = trim($final);

        return $final !== '' ? $final : null;
    }
}
