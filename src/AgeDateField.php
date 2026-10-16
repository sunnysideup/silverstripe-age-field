<?php

declare(strict_types=1);

namespace Sunnysideup\AgeField;

use DateTimeImmutable;
use DateTimeZone;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DateField;
use SilverStripe\ORM\FieldType\DBDatetime;

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

        return $attributes;
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
            return _t(self::class . '.FUTURE', 'Your date of birth can’t be in the future.');
        }

        $age = $dob->diff($today)->y;

        $tooYoung = $this->minAge !== null && $age < $this->minAge;
        $tooOld = $this->maxAge !== null && $age > $this->maxAge;

        if (!$tooYoung && !$tooOld) {
            return null;
        }

        $placeholders = [
            '{minAge}' => (string) $this->minAge,
            '{maxAge}' => (string) $this->maxAge,
            '{age}' => (string) $age,
        ];

        if ($this->ageErrorMessage !== null) {
            return strtr($this->ageErrorMessage, $placeholders);
        }

        if ($this->minAge !== null && $this->maxAge !== null) {
            return _t(
                self::class . '.BETWEEN',
                'You must be between {minAge} and {maxAge} years old.',
                ['minAge' => $this->minAge, 'maxAge' => $this->maxAge]
            );
        }

        if ($tooYoung) {
            return _t(
                self::class . '.TOO_YOUNG',
                'You must be at least {minAge} years old.',
                ['minAge' => $this->minAge]
            );
        }

        return _t(
            self::class . '.TOO_OLD',
            'You must be {maxAge} years old or younger.',
            ['maxAge' => $this->maxAge]
        );
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
