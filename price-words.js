/**
 * --------------------------------------------------------------------------
 * نمایش مبلغ به حروف زیر فیلدهای قیمت ملکینو
 * --------------------------------------------------------------------------
 * به همه‌ی inputهایی که کلاس price-input دارند (در فرم‌های ثبت آگهی برای
 * هر ۶ نوع ملک و هر نوع معامله، و در فرم ثبت درخواست) وصل می‌شود و عدد
 * تایپ‌شده‌ی کاربر را به حروف فارسی، زیر همان تکست‌باکس نمایش می‌دهد.
 *
 * چون با event delegation روی document کار می‌کند، فیلدهایی که بعداً با
 * جاوااسکریپت ساخته می‌شوند (مثل مراحل فرم درخواست) هم خودکار پوشش
 * داده می‌شوند؛ نیازی به تغییر تک‌تک فرم‌ها نیست.
 * --------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var ONES = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    var TEENS = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    var TENS = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    var HUNDREDS = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    var SCALES = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];

    function threeDigitsWords(n) {
        var parts = [];
        var h = Math.floor(n / 100);
        var rest = n % 100;
        if (h > 0) parts.push(HUNDREDS[h]);
        if (rest >= 10 && rest < 20) {
            parts.push(TEENS[rest - 10]);
        } else {
            var t = Math.floor(rest / 10);
            var o = rest % 10;
            if (t > 0) parts.push(TENS[t]);
            if (o > 0) parts.push(ONES[o]);
        }
        return parts.join(' و ');
    }

    /**
     * تبدیل رشته‌ی عددی (حداکثر ۱۵ رقم) به حروف فارسی.
     * خروجی تهی یعنی ورودی نامعتبر است.
     */
    window.melkinoNumToWordsFa = function (numStr) {
        var s = String(numStr == null ? '' : numStr).replace(/^0+/, '');
        if (s === '') return 'صفر';
        if (!/^\d{1,15}$/.test(s)) return '';
        var groups = [];
        while (s.length > 0) {
            groups.push(s.slice(-3));
            s = s.slice(0, -3);
        }
        var words = [];
        for (var i = groups.length - 1; i >= 0; i--) {
            var n = parseInt(groups[i], 10);
            if (!n) continue;
            var w = threeDigitsWords(n);
            if (SCALES[i]) w += ' ' + SCALES[i];
            words.push(w);
        }
        return words.join(' و ') || 'صفر';
    };

    function toEnglishDigits(value) {
        return String(value == null ? '' : value)
            .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
    }

    function updatePriceWords(input) {
        if (!input || !input.parentNode) return;
        var raw = toEnglishDigits(input.value).replace(/[^0-9]/g, '').replace(/^0+(?=\d)/, '');

        var box = input._melkinoWordsEl;
        if (!box || !document.contains(box)) {
            box = document.createElement('div');
            box.className = 'price-words';
            box.setAttribute('aria-live', 'polite');
            box.style.display = 'none';
            input.parentNode.insertBefore(box, input.nextSibling);
            input._melkinoWordsEl = box;
        }

        if (raw === '' || raw.length > 15) {
            box.style.display = 'none';
            box.textContent = '';
            return;
        }
        var words = window.melkinoNumToWordsFa(raw);
        if (!words) {
            box.style.display = 'none';
            box.textContent = '';
            return;
        }
        box.textContent = words + ' تومان';
        box.style.display = 'block';
    }

    window.melkinoUpdatePriceWords = updatePriceWords;

    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t && t.classList && t.classList.contains('price-input')) {
            updatePriceWords(t);
        }
    });

    function sweep() {
        var inputs = document.querySelectorAll('.price-input');
        for (var i = 0; i < inputs.length; i++) {
            updatePriceWords(inputs[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sweep);
    } else {
        sweep();
    }
})();
