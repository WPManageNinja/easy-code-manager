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
        $source = $condition['source'][0];

        switch ($source) {
            case 'user':
                return $this->evaluateUserCondition($condition['source'][1], $condition['operator'], $condition['value']);
            case 'page':
                return $this->evaluatePageCondition($condition['source'][1], $condition['operator'], $condition['value']);
            default:
                return false;
        }
    }


    private function evaluateUserCondition($key, $operator, $value)
    {
        if ($key == 'authenticated') {
            return is_user_logged_in();
        } else if ($key == 'role') {
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
                if(!$tax) {
                    return false;
                }

                return $this->checkValues($tax, $value, $operator);
            case 'url':
                // get current url
                global $wp;
                $url = isset($wp->request) ? trailingslashit(home_url($wp->request)) : '';
                if (!$url) {
                    return false;
                }
                return $this->checkValues($url, $value, $operator);
            default:
                return false;
        }
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
                return strpos($sourceValue, $dataValue) !== false;
                break;
            case 'doNotContains':
            case 'not_contains':
                $sourceValue = strtolower($sourceValue);
                if (is_string($dataValue)) {
                    $dataValue = strtolower($dataValue);
                }
                return !strpos($sourceValue, $dataValue) !== false;;
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
