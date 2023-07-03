export const calculatePercent = function (currentValue, compareValue, strict = false) {
    if (currentValue == 0) {
        return -100;
    }

    if (!compareValue) {
        return '';
    }

    let percent = (currentValue - compareValue) / compareValue * 100;

    if (strict) {
        return percent.toFixed(2);
    }

    return parseInt(percent);
}
