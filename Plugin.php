<?php

namespace Kanboard\Plugin\TagiHoursView;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Translator;
// use Kanboard\Plugin\TagiHoursView\AgeHelper;  // Helper Class and Filename should be exact
// use Kanboard\Helper;  // Add core Helper for using forms etc. inside external templates

class Plugin extends Base
{
    public function initialize()
    {
        // CSS - Asset Hook
        $this->hook->on('template:layout:css', array('template' => 'plugins/TagiHoursView/Assets/css/tagi-hours-view.min.css'));

        // Template Override
        $this->template->setTemplateOverride('search/results', 'TagiHoursView:search/results');

        // Views - Template Hook
        $this->template->hook->attach(
            'template:project:header:before', 'TagiHoursView:board/project_head_hours', [
                'tagiTimes' => function ($projectId) { return $this->getTimesByProjectId($projectId); }
            ]
        );
        $this->template->hook->attach(
            'template:board:column:header', 'TagiHoursView:board/column_hours', [
                'tagiTimes' => function ($column) { return $this->getTimesForColumn($column); }
            ]
        );
        $this->template->hook->attach(
            'template:dashboard:show:after-filter-box', 'TagiHoursView:dashboard/project_times_summary_all', [
                'tagiTimes' => function ($userId) { return $this->getTimesByUserId($userId); }
            ]
        );
        $this->template->hook->attach(
            'template:dashboard:project:after-title', 'TagiHoursView:dashboard/project_times_summary_single', [
                'tagiTimes' => function ($projectId) { return $this->getTimesByProjectId($projectId); }
            ]
        );
    }

    public function onStartup()
    {
        Translator::load($this->languageModel->getCurrentLanguage(), __DIR__.'/Locale');
    }

    /**
     * Calculates the remaining time for the given estimated
     * and the spent time. In case prevent_negative is True,
     * it returns, for example, no negative values, but
     * 0 then.
     *
     * @param  float  $estimated
     * @param  float  $spent
     * @param  boolean $prevent_negative
     * @return float
     */
    public function calculateRemaining($estimated, $spent, $prevent_negative = True)
    {
        if ($estimated - $spent < 0 && $prevent_negative) {
            return 0;
        } else {
            return $estimated - $spent;
        }
    }

    /**
     * Get the estimated and spent times in the columns for
     * all tasks with a given project id. The method also
     * returns the data categorized into the columns, which
     * are either visible on the dashboard and not (additionally).
     *
     * New since v1.2.0: remaining
     *
     *
     * Array output:
     *
     * [
     *     'all' => [
     *         '_total' => [
     *             'estimated' => 8,
     *             'spent' => 6.5,
     *             'remaining' => 1.5
     *         ],
     *         'column a' => [
     *             'estimated' => 2,
     *             'spent' => 1,
     *             'remaining' => 1
     *         ],
     *         'column b' => [
     *             'estimated' => 5,
     *             'spent' => 4.5,
     *             'remaining' => 0.5
     *         ],
     *         'column not-dashboard' => [
     *             'estimated' => 1,
     *             'spent' => 1,
     *             'remaining' => 0
     *         ]
     *     ],
     *     'dashboard' => [
     *         '_total' => [
     *             'estimated' => 7,
     *             'spent' => 5.5,
     *             'remaining' => 1.5
     *         ],
     *         'column a' => [
     *             'estimated' => 2,
     *             'spent' => 1,
     *             'remaining' => 1
     *         ],
     *         'column b' => [
     *             'estimated' => 5,
     *             'spent' => 4.5,
     *             'remaining' => 0.5
     *         ]
     *     ]
     * ]
     *
     * @param  integer $projectId
     * @return array
     */
    public function getTimesByProjectId($projectId)
    {
        $columns = $this->getColumnsByProjectId($projectId);
        $tasks = $this->getTasksByProjectId($projectId);

        $all = [
            '_total' => [
                'estimated' => 0,
                'spent' => 0,
                'remaining' => 0
            ]
        ];
        $dashboard = $all;

        foreach ($tasks as $task) {
            if (isset($columns[$task['column_id']])) {
                $col_name = $columns[$task['column_id']]['title'];
            } else {
                continue;
            }

            if (!isset($all[$col_name])) {
                $all[$col_name] = ['estimated' => 0, 'spent' => 0, 'remaining' => 0];
                if ($columns[$task['column_id']]['hide_in_dashboard'] != 1) {
                    $dashboard[$col_name] = ['estimated' => 0, 'spent' => 0, 'remaining' => 0];
                }
            }

            // all: column times
            $all[$col_name]['estimated'] += $task['time_estimated'];
            $all[$col_name]['spent'] += $task['time_spent'];
            $all[$col_name]['remaining'] += $this->calculateRemaining($task['time_estimated'], $task['time_spent']);

            // all: total times
            $all['_total']['estimated'] += $task['time_estimated'];
            $all['_total']['spent'] += $task['time_spent'];
            $all['_total']['remaining'] += $this->calculateRemaining($task['time_estimated'], $task['time_spent']);


            // dashboard times
            if ($columns[$task['column_id']]['hide_in_dashboard'] != 1) {
                // dashbord: column times
                $dashboard[$col_name]['estimated'] += $task['time_estimated'];
                $dashboard[$col_name]['spent'] += $task['time_spent'];
                $dashboard[$col_name]['remaining'] += $this->calculateRemaining($task['time_estimated'], $task['time_spent']);

                // dashboard: total times
                $dashboard['_total']['estimated'] += $task['time_estimated'];
                $dashboard['_total']['spent'] += $task['time_spent'];
                $dashboard['_total']['remaining'] += $this->calculateRemaining($task['time_estimated'], $task['time_spent']);
            }
        }

        return [
            'all' => $all,
            'dashboard' => $dashboard
        ];
    }

    /**
     * Get an array with the calculated times for
     * the given column array.
     *
     * Array output:
     *
     * [
     *     'estimated' => 2,
     *     'spent' => 1,
     *     'remaining' => 1
     * ]
     *
     * @param  array $column
     * @return array
     */
    public function getTimesForColumn($column)
    {
        $out = ['estimated' => 0, 'spent' => 0, 'remaining' => 0];
        if (isset($column['tasks'])) {
            foreach ($column['tasks'] as $task) {
                $out['estimated'] += $task['time_estimated'];
                $out['spent'] += $task['time_spent'];
                $out['remaining'] += $this->calculateRemaining($task['time_estimated'], $task['time_spent']);
            }
        }
        return $out;
    }

    /**
     * Basically some kind of wrapper function for getting
     * the array with all the columns for the project.
     *
     * Thus here the array-keys are the column id.
     *
     * @param  integer $projectId
     * @return array
     */
    private function getColumnsByProjectId($projectId)
    {
        $out = [];
        $columns = $this->container['columnModel']->getAll($projectId);
        foreach ($columns as $column) {
            $out[$column['id']] = $column;
        }
        return $out;
    }

    /**
     * Basically some kind of wrapper function for getting
     * the array with all the tasks for the project.
     *
     * @param  integer $projectId
     * @return array
     */
    private function getTasksByProjectId($projectId)
    {
        return $this->container['taskFinderModel']->getAll($projectId);
    }

    /**
     * This one gets all tasks for the user and their
     * respecting times.
     *
     * Array output:
     *
     * [
     *     'estimated' => 2,
     *     'spent' => 1
     * ]
     *
     * @param  integer $userId
     * @return array
     */
    public function getTimesByUserId($userId)
    {
        $out = ['estimated' => 0, 'spent' => 0, 'remaining' => 0];

        $userTasks = $this->container['taskFinderModel']->getUserQuery($userId)->findAll();
        foreach ($userTasks as $task) {
            $out['estimated'] += $task['time_estimated'];
            $out['spent'] += $task['time_spent'];
            $out['remaining'] += $this->calculateRemaining($task['time_estimated'], $task['time_spent']);
        }

        return $out;
    }

    public function getPluginName()
    {
        // Plugin Name MUST be identical to namespace for Plugin Directory to detect updated versions
        // Do not translate the plugin name here
        return 'TagiHoursView';
    }

    public function getPluginDescription()
    {
        return t('Show total hours in different places in the Kanboard app');
    }

    public function getPluginAuthor()
    {
        return 'Tagirijus';
    }

    public function getPluginVersion()
    {
        return '1.3.0';
    }

    public function getCompatibleVersion()
    {
        // Examples:
        // >=1.0.37
        // <1.0.37
        // <=1.0.37
        return '>=1.2.27';
    }

    public function getPluginHomepage()
    {
        return 'https://github.com/Tagirijus/kanboard-TagiHoursView';
    }
}
