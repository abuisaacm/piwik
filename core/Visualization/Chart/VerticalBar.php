<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

namespace Piwik\Visualization\Chart;

use Piwik\Visualization\Chart;

/**
 * Customize & set values for the Vertical bar chart
 *
 * @package Piwik
 * @subpackage Piwik_Visualization
 */
class VerticalBar extends Chart
{
    public function customizeChartProperties()
    {
        parent::customizeChartProperties();

        if ($this->displayPercentageInTooltip) {
            foreach ($this->data as $seriesIndex => &$series) {
                $sum = array_sum($series);

                foreach ($series as $valueIndex => $value) {
                    $value = (float)$value;

                    $percentage = 0;
                    if ($sum > 0) {
                        $percentage = round(100 * $value / $sum);
                    }

                    $this->tooltip['percentages'][$seriesIndex][$valueIndex] = $percentage;
                }
            }
        }
    }
}
