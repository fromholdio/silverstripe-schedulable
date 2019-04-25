<?php

namespace Fromholdio\Schedulable\Forms\GridField;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;

class GridFieldScheduledStatus implements GridField_ColumnProvider
{
    use Injectable;

    public function augmentColumns($gridField, &$columns)
    {
        // Ensure Actions always appears as the last column.
        $key = array_search('Actions', $columns);
        if ($key !== false) {
            unset($columns[$key]);
        }
        $columns = array_merge($columns, array(
            'ScheduledStatus',
            'Actions',
        ));
    }

    public function getColumnsHandled($gridField)
    {
        return ['ScheduledStatus'];
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        if ($columnName == 'ScheduledStatus') {

            $iconClass = '';
            $statusMap = $record->getScheduleStatusMap();
            $statusLabel = $record->getScheduleStatusLabel();

            if (isset($statusMap['embargoed'])) {
                $iconClass = 'font-icon-clock mr-2 text-danger';

            } else if (isset($statusMap['expired'])) {
                $iconClass = 'font-icon-box mr-2';

            } else if (isset($statusMap['expiring'])) {
                $iconClass = 'font-icon-check-mark-circle text-success mr-2';

            } else if ($statusMap['published']) {
                $iconClass = 'font-icon-check-mark-circle text-success mr-2';
                if (!$statusLabel) $statusLabel = 'Published';

            } else {
                $iconClass = 'font-icon-edit mr-2 text-info';
            }

            return '<i class="' . $iconClass . '"></i> ' . $statusLabel;
        }
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        if ($columnName === 'ScheduledStatus') {
            $class = "gridfield-icon scheduledstatus";
            return ['class' => $class];
        }
        return [];
    }

    public function getColumnMetaData($gridField, $columnName)
    {
        switch ($columnName) {
            case 'ScheduledStatus':
                return ['title' => 'Status'];
            default:
                break;
        }
    }

}
