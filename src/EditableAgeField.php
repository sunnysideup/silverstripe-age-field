<?php

declare(strict_types=1);

namespace Sunnysideup\AgeField;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\EditableFormField\EditableDateField;

if (!class_exists(EditableDateField::class)) {
    return;
}

/**
 * UserForms field: "Date of birth (age check)".
 *
 * The CMS author sets a minimum and/or maximum age, and the submitted DOB is
 * validated against today's date - e.g. MinAge = 12 for a "12+" website.
 *
 * @property int $MinAge
 * @property int $MaxAge
 * @property string $AgeErrorMessage
 */
class EditableAgeField extends EditableDateField
{
    private static string $singular_name = 'Date of Birth (age check)';

    private static string $plural_name = 'Date of Birth (age check) fields';

    private static string $table_name = 'EditableAgeField';

    private static array $db = [
        'MinAge' => 'Int',
        'MaxAge' => 'Int',
        'AgeErrorMessage' => 'Varchar(255)',
    ];

    private static array $defaults = [
        'MinAge' => 12,
        'MaxAge' => 0,
    ];

    public function getCMSFields(): FieldList
    {
        $this->beforeUpdateCMSFields(function (FieldList $fields): void {
            // "Default to today" makes no sense for a date of birth.
            $fields->removeByName(['DefaultToToday', 'Default']);

            $fields->addFieldsToTab('Root.Validation', [
                NumericField::create('MinAge', _t(self::class . '.MIN_AGE', 'Minimum age (years)'))
                    ->setHTML5(true)
                    ->setDescription(_t(
                        self::class . '.MIN_AGE_DESC',
                        'e.g. 12 means the person must be 12 or older today. Leave as 0 for no minimum.'
                    )),
                NumericField::create('MaxAge', _t(self::class . '.MAX_AGE', 'Maximum age (years)'))
                    ->setHTML5(true)
                    ->setDescription(_t(
                        self::class . '.MAX_AGE_DESC',
                        'e.g. 17 means the person must not have turned 18 yet. Leave as 0 for no maximum.'
                    )),
                TextField::create('AgeErrorMessage', _t(self::class . '.AGE_ERROR', 'Age error message'))
                    ->setDescription(_t(
                        self::class . '.AGE_ERROR_DESC',
                        'Optional. Shown when the age is out of range. You can use {minAge}, {maxAge} and {age}.'
                    )),
            ]);
        });

        return parent::getCMSFields();
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if ($this->MinAge < 0 || $this->MaxAge < 0) {
            $result->addFieldError(
                'MinAge',
                _t(self::class . '.NEGATIVE', 'Ages can’t be negative.')
            );
        }

        if ($this->MinAge > 0 && $this->MaxAge > 0 && $this->MinAge > $this->MaxAge) {
            $result->addFieldError(
                'MaxAge',
                _t(self::class . '.MAX_LESS_THAN_MIN', 'The maximum age must be the same as or greater than the minimum age.')
            );
        }

        return $result;
    }

    public function getFormField(): AgeDateField
    {
        $field = AgeDateField::create($this->Name, $this->Title ?: false)
            ->setMinAge($this->MinAge ?: null)
            ->setMaxAge($this->MaxAge ?: null)
            ->setAgeErrorMessage($this->AgeErrorMessage)
            ->setFieldHolderTemplate(EditableFormField::class . '_holder')
            ->setTemplate(EditableFormField::class);

        // Applies Required, RightTitle, ExtraClass, custom error message etc.
        $this->doUpdateFormField($field);

        return $field;
    }
}
