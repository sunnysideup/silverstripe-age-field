<?php

declare(strict_types=1);

namespace Sunnysideup\AgeField;

use DateTimeImmutable;
use DateTimeZone;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DateField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\View\Requirements;

/**
 * A date-of-birth field that validates the person's age rather than a fixed date.
 *
 * - MinAge: the person must be at least this old (e.g. 12 for a "12+" site).
 * - MaxAge: the person must be no older than this (optional).
 *
 * Age is calculated in whole years against "today" (DBDatetime::now(), so it
 * respects mocked time in tests). Someone born on 29 Feb turns a year older on
 * 1 Mar in non-leap years, which matches PHP's DateTime::diff().
 */
class AgeDateField extends DateField
{
    protected ?int $minAge = null;

    protected ?int $maxAge = null;

    /**
     * Optional custom message. Supports {minAge}, {maxAge} and {age} placeholders.
     */
    protected ?string $ageErrorMessage = null;

    public function setMinAge(?int $minAge): static
    {
        $this->minAge = ($minAge !== null && $minAge > 0) ? $minAge : null;
        return $this;
    }

    public function getMinAge(): ?int
    {
        return $this->minAge;
    }

    public function setMaxAge(?int $maxAge): static
    {
        $this->maxAge = ($maxAge !== null && $maxAge > 0) ? $maxAge : null;
        return $this;
    }

    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    public function setAgeErrorMessage(?string $message): static
    {
        $message = $message !== null ? trim($message) : null;
        $this->ageErrorMessage = $message !== '' ? $message : null;
        return $this;
    }

    public function getAgeErrorMessage(): ?string
    {
        return $this->ageErrorMessage;
    }

    /**
     * Matches the userforms date field so existing styles/JS still apply.
     */
    public function Type(): string
    {
        return 'date text';
    }

    /**
     * Adds HTML5 min/max attributes so the browser's date picker and the
     * userforms client-side validation block out-of-range dates early.
     * The server-side check in validate() is still the source of truth.
     *
     * Note: the userforms front end validates with the jQuery Validate
     * plugin, which auto-generates "max"/"min" rules from these same
     * min/max attributes and uses its own built-in messages for them
     * (e.g. "Please enter a value less than or equal to ..."), completely
     * ignoring AgeErrorMessage/our translated messages.
     *
     * jQuery Validate looks up a per-field override for a rule's message
     * via jQuery's `.data('msg' + Rule)` (i.e. a "msgMax"/"msgMin" data
     * entry, conventionally written as data-msg-max/data-msg-min in HTML -
     * see https://jqueryvalidation.org/validate/#rules). We seed those with
     * a sensible default here, and Field() below adds a script that keeps
     * them (via jQuery's data cache, not the raw HTML attribute, since
     * jQuery Validate reads the cache) in sync with the specific message
     * (future/too young/too old/between/custom) as the value changes.
     */
    public function getAttributes(): array
    {
        $attributes = parent::getAttributes();

        if (!$this->getHTML5()) {
            return $attributes;
        }

        $today = $this->getToday();

        // Latest allowed DOB: exactly MinAge years ago (or today if no minimum).
        $latest = $this->minAge !== null ? $this->subtractYears($today, $this->minAge) : $today;
        $attributes['max'] = $latest->format('Y-m-d');

        // Earliest allowed DOB: the day after (MaxAge + 1) years ago.
        if ($this->maxAge !== null) {
            $earliest = $this->subtractYears($today, $this->maxAge + 1)->modify('+1 day');
            $attributes['min'] = $earliest->format('Y-m-d');
        }

        $attributes['class'] = trim(($attributes['class'] ?? '') . ' age-date-field');
        $attributes['data-age-min'] = $this->minAge !== null ? (string) $this->minAge : '';
        $attributes['data-age-max'] = $this->maxAge !== null ? (string) $this->maxAge : '';
        $attributes['data-age-msg-future'] = (string) $this->getFutureMessage();
        $attributes['data-age-msg-between'] = (string) $this->getBetweenMessage();
        $attributes['data-age-msg-too-young'] = (string) $this->getTooYoungMessage();
        $attributes['data-age-msg-too-old'] = (string) $this->getTooOldMessage();

        $customMessage = $this->getCustomMessageTemplate();
        if ($customMessage !== null) {
            $attributes['data-age-msg-custom'] = $customMessage;
        }

        // Sensible static fallback for jQuery Validate's per-field message
        // override, in case the script below hasn't run yet (e.g. JS disabled).
        // The script keeps these accurate once a date is actually entered.
        $fallback = $customMessage !== null
            ? str_replace('{age}', '', $customMessage)
            : ($this->minAge !== null && $this->maxAge !== null
                ? $this->getBetweenMessage()
                : ($this->minAge !== null ? $this->getTooYoungMessage() : $this->getFutureMessage()));
        $attributes['data-msg-max'] = $fallback;

        if ($this->maxAge !== null) {
            $attributes['data-msg-min'] = $customMessage !== null
                ? str_replace('{age}', '', $customMessage)
                : ($this->minAge !== null ? $this->getBetweenMessage() : $this->getTooOldMessage());
        }

        return $attributes;
    }

    /**
     * Injects a small script that mirrors getAgeError() in the browser and
     * keeps jQuery Validate's per-field "max"/"min" messages (read via
     * jQuery's data cache - see getAttributes() above) up to date, so the
     * userforms front-end validation shows our message (translated / custom
     * AgeErrorMessage) instead of jQuery Validate's generic built-in text
     * ("Please enter a value less than or equal to ...").
     */
    public function Field($properties = [])
    {
        if ($this->getHTML5()) {
            Requirements::customScript(<<<'JS'
(function () {
    function wholeYears(dob, today) {
        var age = today.getFullYear() - dob.getFullYear();
        var monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        return age;
    }

    function parseISODate(value) {
        if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return null;
        }
        var parts = value.split('-');
        var date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        return isNaN(date.getTime()) ? null : date;
    }

    function resolveMessage(input) {
        var value = input.value;
        var dob = parseISODate(value);

        if (!dob) {
            return '';
        }

        var today = new Date();
        today.setHours(0, 0, 0, 0);
        dob.setHours(0, 0, 0, 0);

        if (dob > today) {
            return input.dataset.ageMsgFuture || '';
        }

        var minAge = input.dataset.ageMin ? parseInt(input.dataset.ageMin, 10) : null;
        var maxAge = input.dataset.ageMax ? parseInt(input.dataset.ageMax, 10) : null;
        var age = wholeYears(dob, today);
        var tooYoung = minAge !== null && age < minAge;
        var tooOld = maxAge !== null && age > maxAge;

        if (!tooYoung && !tooOld) {
            return '';
        }

        if (input.dataset.ageMsgCustom) {
            return input.dataset.ageMsgCustom.replace('{age}', age);
        }

        if (minAge !== null && maxAge !== null) {
            return input.dataset.ageMsgBetween || '';
        }

        return tooYoung
            ? (input.dataset.ageMsgTooYoung || '')
            : (input.dataset.ageMsgTooOld || '');
    }

    function checkAgeField(input) {
        var message = resolveMessage(input);

        // Native constraint validation (if anything still consults it).
        input.setCustomValidity(message);

        // jQuery Validate (used by the userforms front end) reads per-field
        // message overrides from jQuery's data cache, not the raw HTML
        // attribute, so update it via jQuery(...).data() to keep it live.
        if (typeof jQuery !== 'undefined') {
            jQuery(input).data('msgMax', message).data('msgMin', message);
        }
    }

    function init(input) {
        if (input.dataset.ageFieldInitialised) {
            return;
        }
        input.dataset.ageFieldInitialised = '1';
        checkAgeField(input);
        input.addEventListener('input', function () { checkAgeField(input); });
        input.addEventListener('change', function () { checkAgeField(input); });
    }

    function initAll() {
        var inputs = document.querySelectorAll('input.age-date-field');
        for (var i = 0; i < inputs.length; i++) {
            init(inputs[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
JS, 'sunnysideup-age-field');
        }

        return parent::Field($properties);
    }

    /**
     * CMS 6 validation: runs after DateField's own checks (format etc.).
     */
    public function validate(): ValidationResult
    {
        $this->beforeExtending('updateValidate', function (ValidationResult $result): void {
            // If the date itself is invalid, DateField has already reported it.
            if (!$result->isValid()) {
                return;
            }

            $error = $this->getAgeError();

            if ($error !== null) {
                $result->addFieldError($this->getName(), $error, ValidationResult::TYPE_ERROR, 'age');
            }
        });

        return parent::validate();
    }

    /**
     * Returns an error message if the entered DOB is out of range, or null if it's fine
     * (including when the field is empty - "Required" is handled separately).
     */
    public function getAgeError(): ?string
    {
        $dob = $this->getDateOfBirth();

        if ($dob === null) {
            return null;
        }

        $today = $this->getToday();

        if ($dob > $today) {
            return $this->getFutureMessage();
        }

        $age = $dob->diff($today)->y;

        $tooYoung = $this->minAge !== null && $age < $this->minAge;
        $tooOld = $this->maxAge !== null && $age > $this->maxAge;

        if (!$tooYoung && !$tooOld) {
            return null;
        }

        if ($this->ageErrorMessage !== null) {
            return strtr($this->ageErrorMessage, [
                '{minAge}' => (string) $this->minAge,
                '{maxAge}' => (string) $this->maxAge,
                '{age}' => (string) $age,
            ]);
        }

        if ($this->minAge !== null && $this->maxAge !== null) {
            return $this->getBetweenMessage();
        }

        if ($tooYoung) {
            return $this->getTooYoungMessage();
        }

        return $this->getTooOldMessage();
    }

    protected function getFutureMessage(): string
    {
        return _t(self::class . '.FUTURE', 'Your date of birth can’t be in the future.');
    }

    protected function getBetweenMessage(): string
    {
        return _t(
            self::class . '.BETWEEN',
            'You must be between {minAge} and {maxAge} years old.',
            ['minAge' => $this->minAge, 'maxAge' => $this->maxAge]
        );
    }

    protected function getTooYoungMessage(): string
    {
        return _t(
            self::class . '.TOO_YOUNG',
            'You must be at least {minAge} years old.',
            ['minAge' => $this->minAge]
        );
    }

    protected function getTooOldMessage(): string
    {
        return _t(
            self::class . '.TOO_OLD',
            'You must be {maxAge} years old or younger.',
            ['maxAge' => $this->maxAge]
        );
    }

    /**
     * Resolves {minAge}/{maxAge} in the custom AgeErrorMessage ahead of time
     * (known at render time), leaving {age} for the client-side script to
     * fill in once a date of birth is entered.
     */
    protected function getCustomMessageTemplate(): ?string
    {
        if ($this->ageErrorMessage === null) {
            return null;
        }

        return strtr($this->ageErrorMessage, [
            '{minAge}' => (string) $this->minAge,
            '{maxAge}' => (string) $this->maxAge,
        ]);
    }

    /**
     * Calculates the age (in whole years) from the current value, or null if no valid date.
     */
    public function getAge(): ?int
    {
        $dob = $this->getDateOfBirth();
        $today = $this->getToday();

        return ($dob !== null && $dob <= $today) ? $dob->diff($today)->y : null;
    }

    protected function getDateOfBirth(): ?DateTimeImmutable
    {
        // dataValue() is DateField's normalised ISO (Y-m-d) value.
        $value = $this->dataValue();

        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->getTimezone());

        return $date !== false ? $date : null;
    }

    protected function getToday(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . DBDatetime::now()->getTimestamp()))
            ->setTimezone($this->getTimezone())
            ->setTime(0, 0);
    }

    protected function getTimezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Subtracts whole years, clamping 29 Feb to 28 Feb in non-leap years
     * (DateTime::modify() would roll over to 1 Mar instead).
     */
    protected function subtractYears(DateTimeImmutable $date, int $years): DateTimeImmutable
    {
        $year = (int) $date->format('Y') - $years;
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');

        if (!checkdate($month, $day, $year)) {
            $day = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        }

        return $date->setDate($year, $month, $day);
    }
}
