<?php

namespace Ci\App\Core\Extensions;

use Fromholdio\CMSMessageField\Forms\CMSMessageField;
use SilverStripe\Admin\AdminController;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

/**
 * @property-read DataObject&self $owner
 */
class Schedulable extends Extension
{
    public const STATUS_EMBARGOED = 'embargoed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DRAFT = 'draft';

    /**
     * Cache for has_extension checks to avoid repeated reflection calls
     * @var array
     */
    private static $_is_versioned_cache = [];

    private static $db = [
        'ScheduleEmbargoDateTime'   =>  'Datetime',
        'ScheduleExpiryDateTime'    =>  'Datetime'
    ];

    private static $scaffold_cms_fields_settings = [
        'ignoreFields' => [
            'ScheduleEmbargoDateTime',
            'ScheduleExpiryDateTime',
        ],
    ];

    private static $schedule_status_labels = [
        self::STATUS_EMBARGOED => 'Embargoed until {date}',
        self::STATUS_EXPIRED => 'Expired at {date}',
        self::STATUS_EXPIRING => 'Will expire at {date}',
    ];

    private static $schedule_fields_tab_path = 'Root.Schedule';

    private static $schedule_status_tab_path = 'Root.Main';


    public function getScheduleStatus(): string
    {

        $status = self::STATUS_PUBLISHED;
        $class = get_class($this->owner);

        // Cache the extension check to avoid repeated reflection calls
        if (!isset(self::$_is_versioned_cache[$class])) {
            self::$_is_versioned_cache[$class] = $class::has_extension(Versioned::class);
        }
        $isVersioned = self::$_is_versioned_cache[$class];

        if ($isVersioned && !$this->owner->isPublished()) {
            $status = self::STATUS_DRAFT;
        }
        elseif ($this->owner->isScheduleEmbargoed()) {
            $status = self::STATUS_EMBARGOED;
        }
        elseif ($this->owner->hasScheduleExpired()) {
            $status = self::STATUS_EXPIRED;
        }
        elseif ($this->owner->isScheduledToExpire()) {
            $status = self::STATUS_EXPIRING;
        }

        return $status;
    }

    public function getScheduleStatusLabel(): ?string
    {
        $labels = $this->owner->config()->get('schedule_status_labels');
        $status = $this->getScheduleStatus();
        $label = $labels[$status] ?? null;
        if (!empty($label))
        {
            $date = match ($status) {
                self::STATUS_EMBARGOED => $this->owner->dbObject('ScheduleEmbargoDateTime')->Nice(),
                self::STATUS_EXPIRED => $this->owner->dbObject('ScheduleExpiryDateTime')->Nice(),
                self::STATUS_EXPIRING => $this->owner->dbObject('ScheduleExpiryDateTime')->Nice(),
                default => '',
            };
            $label = str_replace('{date}', $date, $label);
        }
        return $label;
    }


    public function isScheduleEmbargoed(): bool
    {
        return !empty($this->owner->ScheduleEmbargoDateTime)
            && $this->owner->dbObject('ScheduleEmbargoDateTime')->InFuture();
    }

    public function hasScheduleExpired(): bool
    {
        return !empty($this->owner->ScheduleExpiryDateTime)
            && $this->owner->dbObject('ScheduleExpiryDateTime')->InPast();
    }

    public function isScheduledToExpire(): bool
    {
        return !empty($this->owner->ScheduleExpiryDateTime)
            && $this->owner->dbObject('ScheduleExpiryDateTime')->InFuture();
    }


    protected function updateStatusFlags(array &$flags): void
    {
        /** @var DataObject $class */
        $class = get_class($this->owner);
        $isVersioned = $class::has_extension(Versioned::class);
        if ($isVersioned && !$this->owner->isPublished()) {
            return;
        }
        if ($this->owner->isScheduleEmbargoed()) {
            $date = $this->owner->dbObject('ScheduleEmbargoDateTime')->Nice();
            $flags['schedule_'.self::STATUS_EMBARGOED] = [
                'text' => _t(__CLASS__ . '.FLAG_EMBARGOED_SHORT', 'Embargoed'),
                'title' => _t(__CLASS__ . '.FLAG_EMBARGOED_HELP', 'Item is embargoed until {date}', ['date' => $date]),
            ];
        }
        elseif ($this->owner->hasScheduleExpired()) {
            $date = $this->owner->dbObject('ScheduleExpiryDateTime')->Nice();
            $flags['schedule_'.self::STATUS_EXPIRED] = [
                'text' => _t(__CLASS__ . '.FLAG_EXPIRED_SHORT', 'Expired'),
                'title' => _t(__CLASS__ . '.FLAG_EXPIRED_HELP', 'Item expired on {date}', ['date' => $date]),
            ];
        }
        elseif ($this->owner->isScheduledToExpire()) {
            $date = $this->owner->dbObject('ScheduleExpiryDateTime')->Nice();
            $flags['schedule_'.self::STATUS_EXPIRING] = [
                'text' => _t(__CLASS__ . '.FLAG_EXPIRING_SHORT', 'Expiring'),
                'title' => _t(__CLASS__ . '.FLAG_EXPIRING_HELP', 'Item will expire on {date}', ['date' => $date]),
            ];
        }
    }


    public function augmentSQL(SQLSelect $query, ?DataQuery $dataQuery = null): void
    {
        if (Controller::curr() instanceof AdminController) {
            return;
        }
        $stage = Versioned::get_stage();
        if ($stage === Versioned::LIVE || !Permission::check('VIEW_DRAFT_CONTENT'))
        {
            $tableName = DataObject::getSchema()->tableForField(get_class($this->owner), 'ScheduleEmbargoDateTime');
            $query->addWhere([
                ['"'.$tableName.'"."ScheduleEmbargoDateTime" < ? OR "'.$tableName.'"."ScheduleEmbargoDateTime" IS NULL' => Convert::raw2sql(DBDatetime::now())],
                ['"'.$tableName.'"."ScheduleExpiryDateTime" > ? OR "'.$tableName.'"."ScheduleExpiryDateTime" IS NULL' => Convert::raw2sql(DBDatetime::now())]
            ]);
        }
    }

    public function canView(?Member $member = null): ?bool
    {
        $can = null;
        if (!Controller::curr() instanceof AdminController) {
            $stage = Versioned::get_stage();
            if ($stage === Versioned::LIVE || !Permission::check('VIEW_DRAFT_CONTENT')) {
                $can = (!$this->owner->isScheduleEmbargoed() && !$this->owner->hasScheduleExpired());
            }
        }
        return $can;
    }


    public function updateCMSFields(FieldList $fields): void
    {

        $cmsFields = $this->getScheduleCMSFields();
        if ($cmsFields->count() > 0) {
            $fieldsTabPath = $this->owner->config()->get('schedule_fields_tab_path');
            if (!empty($fieldsTabPath)) {
                $fields->addFieldsToTab($fieldsTabPath, $this->getScheduleCMSFields()->toArray());
            }
        }

        $statusFields = $this->getScheduleStatusCMSFields();

        if ($statusFields->count() > 0) {
            $statusTabPath = $this->owner->config()->get('schedule_status_tab_path');
            if (!empty($statusTabPath)) {
                $statusTab = $fields->findOrMakeTab($statusTabPath);
                $statusFields = array_reverse($statusFields->toArray());
                foreach ($statusFields as $statusField) {
                    $statusTab->unshift($statusField);
                }
            }
        }

    }


    public function getScheduleStatusCMSFields(): FieldList
    {
        $fields = FieldList::create();
        $label = $this->getScheduleStatusLabel();
        if (!empty($label)) {
            $fields->push(CMSMessageField::create('ScheduleStatusMessage', <<<HTML
<p><strong>{$label}</strong></p>
HTML
            ));
        }
        return $fields;
    }

    public function getScheduleCMSFields(): FieldList
    {
        return FieldList::create(
            DatetimeField::create('ScheduleEmbargoDateTime', 'Embargoed until'),
            DatetimeField::create('ScheduleExpiryDateTime', 'Expires after'),
            CMSMessageField::create('ScheduleHelpMessage', <<<HTML
<p>Scheduling only applies to published content. Unpublished/draft items are never displayed publicly.</p>
HTML
            )
        );
    }
}
