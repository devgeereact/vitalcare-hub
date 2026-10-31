<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

use Vitalcare\Http\HttpError;

/** Field specs for the CMS records (see Record), and the course copy guard. */
final class Specs
{
    public const PERSON = [
        'firstName' => ['first_name', 'text', ['max' => 100, 'required' => true]],
        'lastName' => ['last_name', 'text', ['max' => 100, 'required' => true]],
        'email' => ['email', 'email'],
        'phone' => ['phone', 'phone'],
        'jobTitle' => ['job_title', 'text', ['max' => 120]],
        'organisationId' => ['organisation_id', 'uuid'],
        'remindersOptOut' => ['reminders_opt_out', 'bool', ['default' => false]],
        'status' => ['status', 'enum', ['values' => ['active', 'archived'], 'default' => 'active']],
    ];

    public const ORGANISATION = [
        'name' => ['name', 'text', ['max' => 190, 'required' => true]],
        'kind' => ['kind', 'enum', ['values' => ['care_home', 'employer', 'other'], 'default' => 'employer']],
        'contactName' => ['contact_name', 'text', ['max' => 190]],
        'contactEmail' => ['contact_email', 'email'],
        'phone' => ['phone', 'phone'],
        'address' => ['address', 'longtext', ['max' => 500]],
        'reminderCopy' => ['reminder_copy', 'bool', ['default' => false]],
        'status' => ['status', 'enum', ['values' => ['active', 'disabled'], 'default' => 'active']],
    ];

    public const COURSE = [
        'slug' => ['slug', 'slug', ['required' => true]],
        'title' => ['title', 'text', ['max' => 190, 'required' => true]],
        'summary' => ['summary', 'longtext', ['max' => 5000, 'required' => true]],
        'durationText' => ['duration_text', 'text', ['max' => 80, 'blank' => true]],
        'hours' => ['hours', 'decimal'],
        'format' => ['format', 'enum', ['values' => ['in_person', 'online', 'blended'], 'default' => 'in_person']],
        'pricePence' => ['price_pence', 'int', ['min' => 0, 'max' => 10000000]],
        'priceNote' => ['price_note', 'text', ['max' => 160, 'blank' => true]],
        'assessmentMethod' => ['assessment_method', 'text', ['max' => 255, 'blank' => true]],
        'renewalMonths' => ['renewal_months', 'int', ['min' => 1, 'max' => 120]],
        'accreditationNote' => ['accreditation_note', 'longtext', ['max' => 500, 'blank' => true]],
        'published' => ['published', 'bool', ['default' => false]],
        'sortOrder' => ['sort_order', 'int', ['min' => -1000, 'max' => 1000, 'default' => 0]],
        'publishedAt' => ['published_at', 'datetime', ['readonly' => true]],
    ];

    public const SESSION = [
        'courseId' => ['course_id', 'uuid', ['required' => true]],
        'startsAt' => ['starts_at', 'datetime', ['required' => true]],
        'endsAt' => ['ends_at', 'datetime', ['required' => true]],
        'venue' => ['venue', 'text', ['max' => 255]],
        'onlineLink' => ['online_link', 'url'],
        'trainerName' => ['trainer_name', 'text', ['max' => 190, 'blank' => true]],
        'capacity' => ['capacity', 'int', ['min' => 1, 'max' => 500, 'default' => 12]],
        'status' => ['status', 'enum', ['values' => ['scheduled', 'cancelled', 'completed'], 'default' => 'scheduled']],
    ];

    /** The public shape of a course: owner-entered facts only, no ids, drafts or timestamps. */
    public const PUBLIC_COURSE = ['slug', 'title', 'summary', 'durationText', 'hours', 'format', 'pricePence', 'priceNote', 'assessmentMethod', 'renewalMonths', 'accreditationNote'];

    /**
     * D-018 item 9: CQC does not approve, endorse or certify training providers, so course text may not say
     * it does (claims register: cqc_claims can never be proven).
     */
    public static function cqcProblem(string $text): bool
    {
        return preg_match('/\bCQC\b[\s\-]*(approv|endors|certif|accredit|complian|ready|register|recogni|regulated training)/i', $text) === 1
            || preg_match('/\b(approved|endorsed|certified|accredited|recognised|recognized)\s+(by\s+)?(the\s+)?(CQC|Care Quality Commission)\b/i', $text) === 1
            || preg_match('/\bCare Quality Commission\b[\s\-]*(approv|endors|certif|accredit|complian)/i', $text) === 1;
    }

    /** @param array<string,mixed> $values column => value */
    public static function guardCourseCopy(Input $in, array $values): void
    {
        $byCol = [];
        foreach (self::COURSE as $key => $def) {
            $byCol[$def[0]] = $key;
        }
        foreach (['title', 'summary', 'duration_text', 'price_note', 'assessment_method', 'accreditation_note'] as $col) {
            if (is_string($values[$col] ?? null) && self::cqcProblem($values[$col])) {
                $in->error($byCol[$col], 'CQC does not approve or endorse training. Remove this wording.');
            }
        }
    }

    /**
     * Checks that ids sent in a body point at real rows; adds a field error otherwise.
     *
     * @param array<string,mixed> $values column => value
     * @param array<string,array{0:string,1:string,2?:string}> $refs column => [api key, table, extra where]
     */
    public static function refs(\PDO $pdo, Input $in, array $values, array $refs): void
    {
        foreach ($refs as $col => $ref) {
            $id = $values[$col] ?? null;
            if (is_string($id) && !Record::exists($pdo, $ref[1], $id, $ref[2] ?? '1=1')) {
                $in->error($ref[0], 'Choose one from the list.');
            }
        }
    }

    public static function notFoundUnless(bool $ok): void
    {
        if (!$ok) {
            throw HttpError::notFound();
        }
    }
}
