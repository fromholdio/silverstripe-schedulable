<?php

namespace Fromholdio\Schedulable\Extensions;

use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;

class Schedulable extends DataExtension
{
    private static $db = [
        'ScheduleEmbargoDateTime'   =>  'Datetime',
        'ScheduleExpiryDateTime'    =>  'Datetime'
    ];

    public function updateSummaryFields(&$fields)
    {
        if (isset($fields['isPublishedNice'])) {
            unset($fields['isPublishedNice']);
        }
    }

    public function updateCMSFields(FieldList $fields)
    {
        $statusLabel = $this->owner->getScheduleStatusLabel();

        if ($statusLabel) {

            $statusMap = $this->owner->getScheduleStatusMap();
            if (isset($statusMap['published']) && !$statusMap['published']) {
                unset($statusMap['published']);
                $statusMap['unpublished'] = true;
            }

            $statusCSSClass = implode(' ', array_keys($statusMap));

            $statusHeader = '<h4 class="'
                . $statusCSSClass
                . '">Scheduling Status: '
                . '<span>'
                . $statusLabel
                . '</span></h4><br>';

            $statusHeaderField = LiteralField::create('SchedulingStatusHeader', $statusHeader);
            $statusHeaderField->addExtraClass($statusCSSClass);

            $fields->insertBefore('Title', $statusHeaderField);
        }

        $fields->addFieldsToTab(
            'Root.Scheduling',
            [
                HeaderField::create('SchedulingHeader', 'Scheduling Config', 3),
                LiteralField::create(
                    'SchedulingLiteral',
                    "<p style=\"font-weight:bold;\">"
                    . "For security, scheduling applies only to Published content. "
                    . "Unpublished/Draft items are never displayed publicly."
                    . "</p><br>"
                ),
                $embargoField = DatetimeField::create('ScheduleEmbargoDateTime', 'Embargoed Until'),
                $expiryField = DatetimeField::create('ScheduleExpiryDateTime', 'Expires After')
            ]
        );
    }

    public function getScheduleStatusMap()
    {
        $status = [];

        if ($this->owner->IsScheduleEmbargoed()) {
            $status['embargoed'] = $this->owner->obj('ScheduleEmbargoDateTime')->Nice();
        }

        if ($this->owner->IsScheduleExpired()) {
            $status['expired'] = $this->owner->obj('ScheduleExpiryDateTime')->Nice();
        }

        if ($this->owner->WillScheduleExpire()) {
            $status['expiring'] = $this->owner->obj('ScheduleExpiryDateTime')->Nice();
        }

        $status['published'] = ($this->owner->isPublished());

        return $status;
    }

    public function getScheduleStatusLabel()
    {
        $map = $this->owner->getScheduleStatusMap();

        if (isset($map['published']) && !$map['published']) return 'Saved as Draft on ' . $this->owner->dbObject('LastEdited')->Nice();
        if (isset($map['embargoed'])) return 'Embargoed until ' . $map['embargoed'];
        if (isset($map['expired'])) return 'Expired at ' . $map['expired'];
        if (isset($map['expiring'])) return 'Will expire at ' . $map['expiring'];

        return false;
    }

    public function IsScheduleEmbargoed()
    {
        if ($this->owner->ScheduleEmbargoDateTime) {
            return ($this->owner->obj('ScheduleEmbargoDateTime')->InFuture());
        }
        return false;
    }

    public function IsScheduleExpired()
    {
        if ($this->owner->ScheduleExpiryDateTime) {
            return ($this->owner->obj('ScheduleExpiryDateTime')->InPast());
        }
        return false;
    }

    public function WillScheduleExpire()
    {
        if ($this->owner->ScheduleExpiryDateTime) {
            return ($this->owner->obj('ScheduleExpiryDateTime')->InFuture());
        }
        return false;
    }

    public function augmentSQL(SQLSelect $query, DataQuery $dataQuery = null)
    {
        if (Controller::has_curr() && Controller::curr() instanceof LeftAndMain) {
            return;
        }

        $stage = Versioned::get_stage();
        if ($stage === Versioned::LIVE || !Permission::check('VIEW_DRAFT_CONTENT')) {

            $tableName = DataObject::getSchema()->tableForField(get_class($this->owner), 'ScheduleEmbargoDateTime');
            $query->addWhere([
                ['"'.$tableName.'"."ScheduleEmbargoDateTime" < ? OR "'.$tableName.'"."ScheduleEmbargoDateTime" IS NULL'    =>  Convert::raw2sql(DBDatetime::now())],
                ['"'.$tableName.'"."ScheduleExpiryDateTime" > ? OR "'.$tableName.'"."ScheduleExpiryDateTime" IS NULL'    =>  Convert::raw2sql(DBDatetime::now())]
            ]);
        }
    }

    public function canView($member = null)
    {
        if (Controller::has_curr() && Controller::curr() instanceof LeftAndMain) {
            return;
        }

        $stage = Versioned::get_stage();
        if ($stage === Versioned::LIVE || !Permission::check('VIEW_DRAFT_CONTENT')) {
            return (!$this->owner->IsScheduleEmbargoed() && !$this->owner->IsScheduleExpired());
        }
    }
}
