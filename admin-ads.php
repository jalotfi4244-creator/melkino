<!-- =========================================================
     ADS
     ========================================================= -->

<div
    class="tab-content"
    id="tab-ads"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                مدیریت آگهی‌ها
            </span>

            <span
                style="font-size:12px;color:var(--text-secondary);"
                id="adsResultCount"
            >
                0 آگهی
            </span>

            <button
                type="button"
                class="btn-primary"
                style="margin-inline-start:auto;"
                onclick="adminCreateNewAd()"
            >
                ➕ ثبت آگهی جدید
            </button>

        </div>


        <div class="ads-toolbar">

            <input
                id="adsSearch"
                type="search"
                placeholder="جستجو بر اساس عنوان، کد، محله، تلفن یا نام مالک..."
                autocomplete="off"
            >


            <select id="adsPropertyFilter">

                <option value="all">
                    همه نوع ملک
                </option>

                <option value="آپارتمان">
                    آپارتمان
                </option>

                <option value="ویلا">
                    ویلا
                </option>

                <option value="زمین">
                    زمین
                </option>

                <option value="باغ">
                    باغ
                </option>

                <option value="تجاری">
                    تجاری
                </option>

                <option value="اداری">
                    اداری
                </option>

                <option value="مغازه">
                    مغازه
                </option>

            </select>


            <select id="adsTransactionFilter">

                <option value="all">
                    همه معاملات
                </option>

                <option value="فروش">
                    فروش
                </option>

                <option value="اجاره">
                    اجاره
                </option>

                <option value="رهن کامل">
                    رهن کامل
                </option>

                <option value="رهن و اجاره">
                    رهن و اجاره
                </option>

                <option value="پیش فروش">
                    پیش فروش
                </option>

            </select>


            <select id="adsSort">

                <option value="newest">
                    جدیدترین
                </option>

                <option value="oldest">
                    قدیمی‌ترین
                </option>

                <option value="priceHigh">
                    قیمت بیشتر
                </option>

                <option value="priceLow">
                    قیمت کمتر
                </option>

                <option value="title">
                    عنوان
                </option>

            </select>

        </div>


        <div class="ads-toolbar-actions">

            <button
                class="btn-filter active"
                onclick="filterAds('all', this)"
            >
                همه
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('pending', this)"
            >
                در انتظار تایید
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('published', this)"
            >
                فعال
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('vip', this)"
            >
                ⭐ VIP
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('suspended', this)"
            >
                معلق
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('sold', this)"
            >
                فروخته شده
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('rejected', this)"
            >
                رد شده
            </button>

            <button
                class="btn-secondary"
                onclick="resetAdFilters()"
            >
                پاک کردن فیلترها
            </button>

        </div>


        <div
            class="bulk-bar"
            id="bulkBar"
        >

            <div>
                <strong id="selectedCount">
                    0
                </strong>

                آگهی انتخاب شده
            </div>


            <div class="bulk-actions">

                <button
                    class="btn-icon-sm success"
                    onclick="bulkChangeStatus('published')"
                >
                    ✅ انتشار
                </button>

                <button
                    class="btn-icon-sm gold"
                    onclick="bulkChangeStatus('suspended')"
                >
                    ⏸ تعلیق
                </button>

                <button
                    class="btn-icon-sm danger"
                    onclick="bulkDeleteAds()"
                >
                    🗑 حذف
                </button>

            </div>

        </div>


        <div id="adsListContainer"></div>


        <div class="pagination-bar">

            <div
                style="font-size:12px;color:var(--text-secondary);"
                id="adsPaginationInfo"
            ></div>

            <div
                class="pagination"
                id="adsPagination"
            ></div>

        </div>

    </div>

</div>


