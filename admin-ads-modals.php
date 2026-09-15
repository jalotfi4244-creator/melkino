<!-- =========================================================
     DETAIL MODAL
     ========================================================= -->

<div
    class="modal-overlay"
    id="adDetailModal"
>

    <div class="modal-box">

        <div class="modal-header">

            <h3>
                جزئیات کامل آگهی
            </h3>

            <button
                class="modal-close"
                onclick="closeModal('adDetailModal')"
            >
                ✕
            </button>

        </div>


        <div
            id="adDetailContent"
            style="
                display:flex;
                flex-direction:column;
                gap:var(--space-2);
            "
        ></div>

    </div>

</div>


<!-- =========================================================
     EDIT MODAL
     ========================================================= -->

<div
    class="modal-overlay"
    id="adEditModal"
>

    <div class="modal-box">

        <div class="modal-header">

            <h3>
                ✏️ ویرایش آگهی
            </h3>

            <button
                class="modal-close"
                onclick="closeModal('adEditModal')"
            >
                ✕
            </button>

        </div>


        <div
            id="adEditContent"
            style="
