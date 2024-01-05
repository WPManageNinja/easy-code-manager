<?php

namespace FluentSnippets\App\Services;

class FluentSnippetCondition
{
    public function evaluate($conditionSettings)
    {
        if (empty($conditionSettings) || empty($conditionSettings['status']) || $conditionSettings['status'] != 'yes' || empty($conditionSettings['items'])) {
            return true;
        }
        $conditionItems = array_filter($conditionSettings['items']);
        if (!$conditionItems) {
            return true;
        }
        foreach ($conditionItems as $conditions) {
            if ($this->evaluateItems($conditions)) {
                return true;
            }
        }
        return false;
    }

    private function evaluateItems($conditions)
    {
        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($condition)) {
                return false;
            }
        }
        return true;
    }

    private function evaluateCondition($condition)
    {
        if (empty($condition['source']) || empty($condition['operator']) || empty($condition['value'])) {
            return false;
        }
        $source = $condition['source'][0];

        switch ($source) {
            case 'user':
                return $this->evaluateUserCondition($condition['source'][1], $condition['operator'], $condition['value']);
            case 'page':
                return $this->evaluatePageCondition($condition['source'][1], $condition['operator'], $condition['value']);
            case 'date':
                return $this->evaluateDateCondition($condition['source'][1], $condition['operator'], $condition['value']);
            case 'fluentcrm':
                return $this->evaluateFluentCrmCondition($condition['source'][1], $condition['operator'], $condition['value']);
            default:
                return false;
        }
    }

    private function evaluateUserCondition($key, $operator, $value)
    {
        if ($key == 'authenticated') {
            return is_user_logged_in();
        }

        if ($key == 'role') {
            $userId = get_current_user_id();
            if ($userId == 0) {
                $roles = [];
            } else {
                $user = get_user_by('ID', $userId);
                $roles = $user->roles;
            }
            return $this->checkValues($roles, $value, $operator);
        }
        return false;
    }

    private function evaluateFluentCrmCondition($key, $operator, $value)
    {
        if (!defined('FLUENTCRM')) {
            return false;
        }

        if ($key == 'exists') {
            return !!fluentcrm_get_current_contact();
        }

        if ($key == 'tags_ids') {
            $currentContact = fluentcrm_get_current_contact();
            if (!$currentContact) {
                $tagIds = [];
            } else {
                $tagIds = $currentContact->tags->pluck('id')->toArray();
            }
            return $this->checkValues($tagIds, $value, $operator);
        }

        if ($key == 'list_ids') {
            $currentContact = fluentcrm_get_current_contact();
            if (!$currentContact) {
                $listIds = [];
            } else {
                $listIds = $currentContact->lists->pluck('id')->toArray();
            }
            return $this->checkValues($listIds, $value, $operator);
        }

        return false;
    }

    private function evaluatePageCondition($key, $operator, $value)
    {
        switch ($key) {
            case 'page_type':
                $currentPageType = $this->getCurrentPageType();
                return $this->checkValues($currentPageType, $value, $operator);
            case 'post_type':
                if (!is_singular() && !is_page()) {
                    return false;
                }

                $postType = get_post_type();
                return $this->checkValues($postType, $value, $operator);
            case 'taxonomy_page':
                $queried_object = get_queried_object();
                $tax = isset($queried_object->taxonomy) ? $queried_object->taxonomy : '';
                if (!$tax) {
                    return false;
                }

                return $this->checkValues($tax, $value, $operator);

            case 'taxonomy_term_page':
                $queried_object = get_queried_object();
                $termId = isset($queried_object->term_id) ? $queried_object->term_id : '';
                if (!$termId) {
                    return false;
                }
                return $this->checkValues($termId, $value, $operator);

            case 'url':
                // get current url
                global $wp;
                $url = isset($wp->request) ? trailingslashit(add_query_arg($_GET, home_url($wp->request))) : '';
                if (!$url) {
                    return false;
                }
                return $this->checkValues($url, $value, $operator);
            case 'page_ids':
                if (!is_singular() && !is_page()) {
                    return false;
                }

                $value = (array) $value;

                $value = array_filter($value);

                if (!$value) {
                    return false;
                }

                $pageId = get_the_ID();
                return $this->checkValues($pageId, $value, $operator);
            default:
                return false;
        }
    }

    private function evaluateDateCondition($key, $operator, $value)
    {
        switch ($key) {
            case 'date_range':
                $currentTime = current_time('timestamp');
                return $this->checkValues($currentTime, $value, $operator);
            case 'day_of_week':
                $dayOfWeek = strtolower(date('D', current_time('timestamp')));
                return $this->checkValues($dayOfWeek, $value, $operator);
            case 'time_range':
                $operator = str_replace('date_', 'number_', $operator);
                $currentTime = date('His', current_time('timestamp'));

                $currentDay = date('Y-m-d', current_time('timestamp'));

                $value = [
                    (int)date('His', strtotime($currentDay . ' ' . $value[0])),
                    (int)date('His', strtotime($currentDay . ' ' . $value[1])),
                ];

                return $this->checkValues($currentTime, $value, $operator);
        }

        return false;
    }

    private function getCurrentPageType()
    {
        global $wp_query;

        if (empty($wp_query)) {
            return '';
        }

        if (is_front_page() || is_home()) {
            return 'is_front_page';
        }
        if (is_singular()) {
            return 'is_singular';
        }
        if (is_archive()) {
            return 'is_archive';
        }
        if (is_search()) {
            return 'is_search';
        }
        if (is_404()) {
            return 'is_404';
        }
        if (is_author()) {
            return 'is_author';
        }

        return '';
    }

    /*
     * $sourceValue = dynamic value
     * $dataValue = user input value
     */
    private function checkValues($sourceValue, $dataValue, $operator)
    {
        switch ($operator) {
            case '=':
                if (is_array($sourceValue)) {
                    return in_array($dataValue, $sourceValue);
                }
                return $sourceValue == $dataValue;
                break;
            case '!=':
                if (is_array($sourceValue)) {
                    return !in_array($dataValue, $sourceValue);
                }
                return $sourceValue != $dataValue;
                break;
            case '>':
                return $sourceValue > $dataValue;
                break;
            case '<':
                return $sourceValue < $dataValue;
                break;
            case '>=':
                return $sourceValue >= $dataValue;
                break;
            case '<=':
                return $sourceValue <= $dataValue;
                break;
            case 'startsWith':
                //  return Str::startsWith($sourceValue, $dataValue);
                break;
            case 'endsWith':
                // return Str::endsWith($sourceValue, $dataValue);
                break;
            case 'contains':
                $sourceValue = strtolower($sourceValue);
                if (is_string($dataValue)) {
                    $dataValue = strtolower($dataValue);
                }
                return str_contains($sourceValue, $dataValue);
                break;
            case 'doNotContains':
            case 'not_contains':
                $sourceValue = strtolower($sourceValue);
                if (is_string($dataValue)) {
                    $dataValue = strtolower($dataValue);
                }
                return !str_contains($sourceValue, $dataValue);
                break;
            case 'length_equal':
                if (is_array($sourceValue)) {
                    return count($sourceValue) == $dataValue;
                }
                $sourceValue = strval($sourceValue);
                return strlen($sourceValue) == $dataValue;
                break;
            case 'length_less_than':
                if (is_array($sourceValue)) {
                    return count($sourceValue) < $dataValue;
                }
                $sourceValue = strval($sourceValue);
                return strlen($sourceValue) < $dataValue;
                break;
            case 'length_greater_than':
                if (is_array($sourceValue)) {
                    return count($sourceValue) > $dataValue;
                }
                $sourceValue = strval($sourceValue);
                return strlen($sourceValue) > $dataValue;
                break;
            case 'match_all':
            case 'in_all':
                $sourceValue = (array)$sourceValue;
                $dataValue = (array)$dataValue;
                sort($sourceValue);
                sort($dataValue);
                return $sourceValue == $dataValue;
                break;
            case 'match_none_of':
            case 'not_in_all':
                $sourceValue = (array)$sourceValue;
                $dataValue = (array)$dataValue;
                return !(array_intersect($sourceValue, $dataValue));
                break;
            case 'in':
                $dataValue = (array)$dataValue;
                if (is_array($sourceValue)) {
                    return !!(array_intersect($sourceValue, $dataValue));
                }
                return in_array($sourceValue, $dataValue);
            case 'not_in':
                $dataValue = (array)$dataValue;
                if (is_array($sourceValue)) {
                    return !(array_intersect($sourceValue, $dataValue));
                }
                return !in_array($sourceValue, $dataValue);
            case 'before':
                return strtotime($sourceValue) < strtotime($dataValue);
            case 'after':
                return strtotime($sourceValue) > strtotime($dataValue);
            case 'date_equal':
                return date('YMD', strtotime($sourceValue)) == date('YMD', strtotime($dataValue));
            case 'date_within':
                $range = [strtotime($dataValue[0]), strtotime($dataValue[1])];
                return strtotime($sourceValue) >= $range[0] && strtotime($sourceValue) <= $range[1];
            case 'date_not_within':
                $range = [strtotime($dataValue[0]), strtotime($dataValue[1])];
                return strtotime($sourceValue) <= $range[0] || strtotime($sourceValue) >= $range[1];
            case 'number_within':
                return $sourceValue >= $dataValue[0] && $sourceValue <= $dataValue[1];
            case 'number_not_within':
                return $sourceValue <= $dataValue[0] || $sourceValue >= $dataValue[1];
            case 'days_before':
                return strtotime($sourceValue) < strtotime("-{$dataValue} days", current_time('timestamp'));
            case 'days_within':
                return strtotime($sourceValue) > strtotime("-{$dataValue} days", current_time('timestamp'));
            case 'is_null':
                return !$sourceValue;
            case 'not_null':
                return !!$sourceValue;
        }
        return false;
    }
}
