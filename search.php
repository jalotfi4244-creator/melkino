<?php

require_once 'header.php';

?>

<style>

/* =========================================================
   MELKINO SEARCH PAGE
   Responsive / RTL / Light + Dark
   ========================================================= */

.main-content {
    flex: 1;
    width: 100%;
    min-height: 0;

    overflow-y: auto;
    overflow-x: hidden;

    box-sizing: border-box;

    padding: 10px 14px 0;

    background: var(--bg);

    -webkit-overflow-scrolling: touch;
}


/* =========================================================
   SEARCH FORM
   ========================================================= */

#searchForm {
    width: 100%;
    min-height: 100%;
    box-sizing: border-box;
}

.search-content {
    width: 100%;
    max-width: 1100px;

    margin: 0 auto;

    box-sizing: border-box;

    padding-bottom: 135px;
}


/* =========================================================
   FILTER CARDS
   ========================================================= */

.filter-group {
    width: 100%;
    box-sizing: border-box;

    margin: 0 0 10px;
    padding: 14px;

    border: 1px solid var(--border);
    border-radius: 15px;

    background: var(--surface);

    box-shadow:
        0 3px 12px rgba(0, 0, 0, .035);
}

.filter-group:last-child {
    margin-bottom: 0;
}


/* =========================================================
   SECTION TITLE
   ========================================================= */

.filter-group-title {
    position: relative;

    display: flex;
    align-items: center;

    margin: 0 0 11px;
    padding-right: 9px;

    color: var(--text-primary);

    font-size: 14px;
    font-weight: 850;

    line-height: 1.5;
}

.filter-group-title::before {
    content: "";

    position: absolute;

    right: 0;
    top: 2px;

    width: 3px;
    height: 17px;

    border-radius: 10px;

    background: var(--primary);
}

.filter-group-title::after {
    content: "";

    flex: 1;

    height: 1px;

    margin-right: 10px;

    background: var(--border);
}


/* =========================================================
   GRID
   ========================================================= */

.row-half {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 10px;
}

.row-third {
    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 10px;
}


/* =========================================================
   FORM GROUP
   ========================================================= */

.form-group {
    display: flex;
    flex-direction: column;

    gap: 4px;

    margin: 0;
}

.form-group label {
    display: block;

    color: var(--text-secondary);

    font-size: 10px;
    font-weight: 700;

    line-height: 1.4;
}


/* =========================================================
   INPUT / SELECT
   ========================================================= */

.form-input,
.form-select {
    width: 100%;
    height: 42px;

    box-sizing: border-box;

    padding: 0 11px;

    border: 1px solid var(--border);
    border-radius: 10px;

    outline: none;

    background: var(--bg);
    color: var(--text-primary);

    font-family: 'Vazirmatn', sans-serif;
    font-size: 12px;

    transition:
        border-color .18s ease,
        box-shadow .18s ease,
        background .18s ease;
}

.form-input::placeholder {
    color: var(--text-secondary);
    opacity: .55;
}

.form-input:hover,
.form-select:hover {
    border-color: var(--primary);
}

.form-input:focus,
.form-select:focus {
    border-color: var(--primary);

    background: var(--surface);

    box-shadow:
        0 0 0 3px
        color-mix(
            in srgb,
            var(--primary) 9%,
            transparent
        );
}


/* =========================================================
   RANGE HINT
   ========================================================= */

.range-hint {
    margin: -3px 0 9px;

    color: var(--text-secondary);

    font-size: 9px;

    line-height: 1.5;
}


/* =========================================================
   FEATURE ROW
   ========================================================= */

.feature-row {
    display: flex;

    align-items: center;
    justify-content: space-between;

    min-height: 43px;

    padding: 4px 1px;

    box-sizing: border-box;

    border-top: 1px solid var(--border);
}

.feature-info {
    display: flex;

    align-items: center;

    gap: 8px;
}

.feature-icon {
    width: 29px;
    height: 29px;

    display: flex;

    align-items: center;
    justify-content: center;

    flex-shrink: 0;

    border-radius: 8px;

    background:
        color-mix(
            in srgb,
            var(--primary) 9%,
            var(--bg)
        );

    color: var(--primary);

    font-size: 14px;
}

.switch-label {
    color: var(--text-primary);

    font-size: 11px;
    font-weight: 700;
}


/* =========================================================
   SWITCH
   ========================================================= */

.switch {
    position: relative;

    display: inline-block;

    width: 42px;
    height: 23px;

    flex-shrink: 0;
}

.switch input {
    width: 0;
    height: 0;

    opacity: 0;
}

.slider {
    position: absolute;

    inset: 0;

    cursor: pointer;

    border-radius: 30px;

    background: var(--border);

    transition: .2s ease;
}

.slider::before {
    content: "";

    position: absolute;

    left: 3px;
    bottom: 3px;

    width: 17px;
    height: 17px;

    border-radius: 50%;

    background: #fff;

    box-shadow:
        0 1px 4px rgba(0,0,0,.18);

    transition: .2s ease;
}

.switch input:checked + .slider {
    background: var(--primary);
}

.switch input:checked + .slider::before {
    transform: translateX(19px);
}


/* =========================================================
   SEARCH ACTIONS
   ========================================================= */

.search-actions {
    position: fixed;

    left: 0;
    right: 0;

    bottom:
        var(--melkino-footer-height, 75px);

    width: 100%;

    display: flex;

    align-items: center;

    gap: 9px;

    padding: 9px 14px;

    box-sizing: border-box;

    background:
        color-mix(
            in srgb,
            var(--bg) 94%,
            transparent
        );

    border-top: 1px solid var(--border);

    backdrop-filter:
        blur(10px);

    -webkit-backdrop-filter:
        blur(10px);

    z-index: 999;

    box-shadow:
        0 -5px 18px rgba(0, 0, 0, .07);
}


/* =========================================================
   RESET
   ========================================================= */

.search-actions .btn-reset {
    height: 46px;

    flex: 0 0 auto;

    padding: 0 16px;

    border: 1px solid var(--border);

    border-radius: 12px;

    background: var(--surface);

    color: var(--text-secondary);

    font-family: 'Vazirmatn', sans-serif;

    font-size: 11px;
    font-weight: 700;

    cursor: pointer;

    transition:
        border-color .18s ease,
        color .18s ease,
        transform .18s ease,
        background .18s ease;
}

.search-actions .btn-reset:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.search-actions .btn-reset:active {
    transform: scale(.97);
}


/* =========================================================
   PRIMARY SEARCH BUTTON
   ========================================================= */

.search-actions .btn-primary-full {
    flex: 1;

    height: 46px;

    display: flex;

    align-items: center;
    justify-content: center;

    gap: 7px;

    padding: 0 14px;

    border: 0;

    border-radius: 12px;

    background:
        linear-gradient(
            135deg,
            var(--primary),
            color-mix(
                in srgb,
                var(--primary) 82%,
                #000 18%
            )
        );

    color: #fff;

    font-family: 'Vazirmatn', sans-serif;

    font-size: 13px;
    font-weight: 850;

    cursor: pointer;

    box-shadow:
        0 5px 15px
        color-mix(
            in srgb,
            var(--primary) 22%,
            transparent
        );

    transition:
        transform .18s ease,
        opacity .18s ease,
        box-shadow .18s ease;
}

.search-actions .btn-primary-full::before {
    content: "⌕";

    font-size: 19px;

    line-height: 1;
}

.search-actions .btn-primary-full:hover {
    box-shadow:
        0 7px 18px
        color-mix(
            in srgb,
            var(--primary) 27%,
            transparent
        );

    transform: translateY(-1px);
}

.search-actions .btn-primary-full:active {
    transform: scale(.985);
    opacity: .9;
}


/* =========================================================
   DESKTOP
   ========================================================= */

@media (min-width: 900px) {

    .main-content {
        padding:
            18px 20px 0;
    }

    .search-content {
        display: grid;

        grid-template-columns:
            repeat(2, minmax(0, 1fr));

        align-items: start;

        gap: 12px;
    }

    /*
     * بعضی بخش‌ها تمام عرض باشند
     */

    .filter-group:nth-child(1),
    .filter-group:nth-child(5) {
        grid-column: 1 / -1;
    }

    .filter-group {
        margin-bottom: 0;
        padding: 15px;
    }

    .search-actions {
        padding-left: 20px;
        padding-right: 20px;
    }
}


/* =========================================================
   TABLET
   ========================================================= */

@media (min-width: 521px) and (max-width: 899px) {

    .main-content {
        padding:
            12px 14px 0;
    }

    .search-content {
        max-width: 760px;
    }

    .filter-group {
        padding: 13px;
    }
}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 520px) {

    .main-content {
        padding:
            7px 9px 0;
    }

    .search-content {
        padding-bottom: 128px;
    }

    .filter-group {
        padding: 11px;

        margin-bottom: 7px;

        border-radius: 13px;
    }

    .filter-group-title {
        font-size: 12px;

        margin-bottom: 8px;
    }

    .filter-group-title::before {
        height: 15px;
    }

    .row-half {
        gap: 7px;
    }

    .form-group {
        gap: 3px;
    }

    .form-group label {
        font-size: 9px;
    }

    .form-input,
    .form-select {
        height: 39px;

        padding:
            0 9px;

        border-radius: 9px;

        font-size: 11px;
    }

    .range-hint {
        font-size: 8px;

        margin-bottom: 7px;
    }

    .feature-row {
        min-height: 39px;
    }

    .feature-icon {
        width: 26px;
        height: 26px;

        font-size: 12px;
    }

    .switch-label {
        font-size: 10px;
    }

    .switch {
        width: 39px;
        height: 21px;
    }

    .slider::before {
        width: 15px;
        height: 15px;
    }

    .switch input:checked + .slider::before {
        transform: translateX(18px);
    }

    .search-actions {
        bottom:
            var(--melkino-footer-height, 75px);

        gap: 7px;

        padding:
            7px 9px;
    }

    .search-actions .btn-reset,
    .search-actions .btn-primary-full {
        height: 43px;
    }

    .search-actions .btn-reset {
        padding:
            0 11px;

        font-size: 10px;
    }

    .search-actions .btn-primary-full {
        font-size: 12px;
    }
}


/* =========================================================
   VERY SMALL MOBILE
   ========================================================= */

@media (max-width: 360px) {

    .row-half {
        grid-template-columns:
            1fr;

        gap: 5px;
    }

    .filter-group {
        padding: 10px;
    }

    .search-actions .btn-reset {
        padding:
            0 9px;
    }
}


/* =========================================================
   DARK MODE
   ========================================================= */

[data-theme="dark"] .filter-group {
    box-shadow:
        0 4px 14px rgba(0, 0, 0, .16);
}

[data-theme="dark"] .form-input,
[data-theme="dark"] .form-select {
    background:
        color-mix(
            in srgb,
            var(--bg) 90%,
            #fff 10%
        );
}

[data-theme="dark"] .search-actions {
    background:
        color-mix(
            in srgb,
            var(--bg) 94%,
            #000 6%
        );

    box-shadow:
        0 -5px 20px rgba(0, 0, 0, .24);
}

</style>


<div class="main-content">

    <form
        method="GET"
        action="search-results.php"
        id="searchForm"
    >

        <div class="search-content">

            <!-- =================================================
                 نوع ملک و معامله
                 ================================================= -->

            <div class="filter-group">

                <div class="filter-group-title">
                    نوع ملک و معامله
                </div>

                <div class="row-half">

                    <div class="form-group">

                        <label for="property_type">
                            نوع ملک
                        </label>

                        <select
                            class="form-select"
                            name="property_type"
                            id="property_type"
                        >

                            <option value="">
                                همه
                            </option>

                            <option value="apartment">
                                آپارتمان
                            </option>

                            <option value="villa">
                                ویلا
                            </option>

                            <option value="commercial">
                                تجاری
                            </option>

                            <option value="land">
                                زمین
                            </option>

                            <option value="garden">
                                باغ
                            </option>

                            <option value="office">
                                اداری
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label for="transaction_type">
                            نوع معامله
                        </label>

                        <select
                            class="form-select"
                            name="transaction_type"
                            id="transaction_type"
                        >

                            <option value="">
                                همه
                            </option>

                            <option value="sell">
                                خرید و فروش
                            </option>

                            <option value="pre_sell">
                                پیش فروش
                            </option>

                            <option value="rent">
                                اجاره
                            </option>

                        </select>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 قیمت
                 ================================================= -->

            <div class="filter-group">

                <div class="filter-group-title">
                    بازه قیمت
                </div>

                <div class="range-hint">
                    قیمت را به تومان وارد کنید
                </div>

                <div class="row-half">

                    <div class="form-group">

                        <label for="price_min">
                            حداقل
                        </label>

                        <input
                            type="text"
                            class="form-input"
                            name="price_min"
                            id="price_min"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="۵۰۰,۰۰۰,۰۰۰"
                        >

                    </div>


                    <div class="form-group">

                        <label for="price_max">
                            حداکثر
                        </label>

                        <input
                            type="text"
                            class="form-input"
                            name="price_max"
                            id="price_max"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="۵,۰۰۰,۰۰۰,۰۰۰"
                        >

                    </div>

                </div>

            </div>


            <!-- =================================================
                 متراژ
                 ================================================= -->

            <div class="filter-group">

                <div class="filter-group-title">
                    بازه متراژ
                </div>

                <div class="range-hint">
                    متراژ به متر مربع
                </div>

                <div class="row-half">

                    <div class="form-group">

                        <label for="area_min">
                            حداقل
                        </label>

                        <input
                            type="number"
                            class="form-input"
                            name="area_min"
                            id="area_min"
                            min="0"
                            placeholder="۵۰"
                        >

                    </div>


                    <div class="form-group">

                        <label for="area_max">
                            حداکثر
                        </label>

                        <input
                            type="number"
                            class="form-input"
                            name="area_max"
                            id="area_max"
                            min="0"
                            placeholder="۳۰۰"
                        >

                    </div>

                </div>

            </div>


            <!-- =================================================
                 مشخصات ملک
                 ================================================= -->

            <div class="filter-group">

                <div class="filter-group-title">
                    مشخصات ملک
                </div>


                <div class="form-group">

                    <label for="bedrooms">
                        تعداد اتاق
                    </label>

                    <select
                        class="form-select"
                        name="bedrooms"
                        id="bedrooms"
                    >

                        <option value="">
                            بدون محدودیت
                        </option>

                        <option value="1">
                            ۱ اتاق
                        </option>

                        <option value="2">
                            ۲ اتاق
                        </option>

                        <option value="3">
                            ۳ اتاق
                        </option>

                        <option value="4">
                            ۴ اتاق
                        </option>

                        <option value="5">
                            ۵ اتاق
                        </option>

                    </select>

                </div>


                <!-- پارکینگ -->

                <div class="feature-row">

                    <div class="feature-info">

                        <div class="feature-icon">
                            🚗
                        </div>

                        <span class="switch-label">
                            پارکینگ
                        </span>

                    </div>

                    <label class="switch">

                        <input
                            type="checkbox"
                            name="parking"
                            value="1"
                        >

                        <span class="slider"></span>

                    </label>

                </div>


                <!-- آسانسور -->

                <div class="feature-row">

                    <div class="feature-info">

                        <div class="feature-icon">
                            🛗
                        </div>

                        <span class="switch-label">
                            آسانسور
                        </span>

                    </div>

                    <label class="switch">

                        <input
                            type="checkbox"
                            name="elevator"
                            value="1"
                        >

                        <span class="slider"></span>

                    </label>

                </div>

            </div>

        </div>


        <!-- =================================================
             ACTION BUTTONS
             ================================================= -->

        <div class="search-actions">

            <button
                type="button"
                class="btn-reset"
                onclick="resetSearch()"
            >
                حذف فیلترها
            </button>

            <button
                type="submit"
                class="btn-primary-full"
            >
                جستجوی املاک
            </button>

        </div>

    </form>

</div>


<script>

/* =========================================================
   RESET SEARCH
   ========================================================= */

function resetSearch() {

    const form =
        document.getElementById('searchForm');

    if (!form) {
        return;
    }

    form.reset();


    form.querySelectorAll(
        'input[type="checkbox"]'
    ).forEach(function (checkbox) {

        checkbox.checked = false;

    });

}


/* =========================================================
   PRICE FORMAT
   ========================================================= */

(function () {

    const inputs =
        document.querySelectorAll(
            'input[name="price_min"], input[name="price_max"]'
        );

    const persianDigits =
        '۰۱۲۳۴۵۶۷۸۹';


    inputs.forEach(function (input) {

        input.addEventListener(
            'input',
            function () {

                let value =
                    this.value || '';


                /*
                 * حذف کاراکترهای غیر عدد
                 */
                value =
                    value.replace(
                        /[^\d۰-۹]/g,
                        ''
                    );


                /*
                 * تبدیل فارسی به انگلیسی
                 */
                value =
                    value.replace(
                        /[۰-۹]/g,
                        function (digit) {

                            return String(
                                persianDigits.indexOf(
                                    digit
                                )
                            );

                        }
                    );


                if (!value) {

                    this.value = '';

                    return;
                }


                /*
                 * جداکننده هزارگان
                 */
                this.value =
                    Number(value)
                        .toLocaleString('en-US');

            }
        );

    });

})();


/* =========================================================
   BEFORE SUBMIT
   جداکننده قیمت حذف می‌شود تا مقدار خام ارسال شود.
   ========================================================= */

document
    .getElementById('searchForm')
    ?.addEventListener(
        'submit',
        function () {

            const priceInputs =
                this.querySelectorAll(
                    'input[name="price_min"], input[name="price_max"]'
                );


            priceInputs.forEach(
                function (input) {

                    input.value =
                        input.value.replace(
                            /,/g,
                            ''
                        );

                }
            );

        }
    );


/* =========================================================
   DETECT FOOTER HEIGHT
   ========================================================= */

(function () {

    function updateFooterHeight() {

        const footer =
            document.querySelector(
                '.bottom-nav'
            );


        if (!footer) {

            document.documentElement.style.setProperty(
                '--melkino-footer-height',
                '75px'
            );

            return;
        }


        const height =
            footer.getBoundingClientRect().height;


        document.documentElement.style.setProperty(
            '--melkino-footer-height',
            Math.ceil(height) + 'px'
        );

    }


    updateFooterHeight();


    window.addEventListener(
        'load',
        updateFooterHeight
    );


    window.addEventListener(
        'resize',
        updateFooterHeight
    );


    if (window.ResizeObserver) {

        const footer =
            document.querySelector(
                '.bottom-nav'
            );


        if (footer) {

            const observer =
                new ResizeObserver(
                    function () {

                        updateFooterHeight();

                    }
                );


            observer.observe(footer);
        }
    }


    setTimeout(
        updateFooterHeight,
        100
    );

    setTimeout(
        updateFooterHeight,
        400
    );

    setTimeout(
        updateFooterHeight,
        800
    );

})();

</script>


<?php

require_once 'footer.php';

?>