<?php require_once 'header.php'; ?>
<style>
    .main-content { flex: 1; overflow-y: auto; padding-bottom: 75px; background: var(--bg); display: flex; flex-direction: column; }
    .step-content { display: none; padding: 0 var(--space-3); flex-direction: column; gap: var(--space-2); animation: fadeIn 0.3s ease forwards; }
    .step-content.active { display: flex; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .step-title { font-size: 20px; font-weight: 800; color: var(--text-primary); margin-bottom: var(--space-1); }
    .step-subtitle { font-size: 14px; color: var(--text-secondary); margin-bottom: var(--space-2); }
    .form-group { display: flex; flex-direction: column; gap: var(--space-1); margin-bottom: var(--space-2); }
    .form-group label { font-size: 14px; font-weight: 600; color: var(--text-primary); }
    .form-input { width: 100%; height: 50px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--bg); padding: 0 var(--space-2); font-size: 15px; font-family: 'Vazirmatn', sans-serif; color: var(--text-primary); outline: none; transition: border 0.2s ease; }
    .form-input:focus { border-color: var(--primary); }
    .options-group { display: flex; flex-wrap: wrap; gap: var(--space-1); }
    .option-btn { padding: 10px 16px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--bg); font-family: 'Vazirmatn', sans-serif; font-size: 14px; font-weight: 500; color: var(--text-secondary); cursor: pointer; transition: all 0.2s ease; text-decoration: none; display: inline-block; }
    .option-btn.selected { background: rgba(6, 78, 78, 0.1); border-color: var(--primary); color: var(--primary); }
    .option-btn:active { transform: scale(0.95); }
    
    /* استایل دکمه‌های ناوبری دقیقاً مانند کد ارسالی */
    .bottom-actions {
        position: sticky;
        bottom: 0;
        width: 100%;
        padding: var(--space-2) var(--space-3);
        background: var(--surface);
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: center;
        gap: var(--space-2);
        z-index: 999;
        box-sizing: border-box;
        margin-top: auto;
        margin-bottom: 0;
    }
    .btn-secondary, .btn-primary-full { height: 52px; border-radius: var(--radius-md); font-weight: 700; font-size: 16px; display: flex; justify-content: center; align-items: center; padding: 0 var(--space-2); box-sizing: border-box; border: none; flex: 1; max-width: 160px; transition: all 0.2s ease; }
    .btn-secondary { background: var(--bg); color: var(--text-secondary); border: 1px solid var(--border); }
    .btn-primary-full { background: var(--primary); color: #ffffff; }
    .request-contact-btn { background: var(--primary); color: #fff; border: none; border-radius: var(--radius-sm); padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
</style>

<div class="main-content" id="mainContent">
    <div style="display:flex; align-items:center; gap:var(--space-1); padding:var(--space-2) var(--space-3); background:var(--surface); border-bottom:1px solid var(--border);">
        <span style="font-weight:700; color:var(--text-primary);">مرحله <span id="stepCounter">۱</span> از ۳</span>
    </div>
    <form id="step1Form" style="padding-top:var(--space-3); display: flex; flex-direction: column; flex: 1;">
        <!-- STEP 1: اطلاعات تماس -->
        <div class="step-content active" id="step1">
            <h2 class="step-title">اطلاعات تماس</h2>
            <p class="step-subtitle">لطفاً اطلاعات خود را وارد کنید.</p>
            
            <input type="hidden" id="regTelegramId" value="">
            
            <div class="form-group"><label>جنسیت</label><div class="options-group" id="regGender"><div class="option-btn selected" onclick="selectOption(this, 'regGender')" data-value="آقا">آقا</div><div class="option-btn" onclick="selectOption(this, 'regGender')" data-value="خانم">خانم</div></div><input type="hidden" id="genderInput" value="آقا"></div>
            <div class="form-group"><label>نام خانوادگی</label><input type="text" class="form-input" id="regLastName" placeholder="نام خانوادگی خود را وارد کنید"></div>
            <div class="form-group"><label>شماره تماس</label><div style="display:flex; align-items:center; gap:var(--space-1);"><input type="tel" class="form-input" id="regPhone" placeholder="مثلاً ۰۹۱۲۳۴۵۶۷۸۹" style="flex:1;"><button type="button" class="request-contact-btn" id="requestContactBtn" onclick="requestPhone()">دریافت شماره</button></div></div>
        </div>

        <!-- STEP 2: نوع معامله -->
        <div class="step-content" id="step2">
            <h2 class="step-title">نوع معامله</h2>
            <p class="step-subtitle">لطفاً نوع معامله‌ی مورد نظر خود را انتخاب کنید.</p>
            <div class="form-group"><label>نوع معامله</label>
                <div class="options-group" id="transactionType">
                    <div class="option-btn selected" onclick="selectOption(this, 'transactionType')" data-value="فروش">خرید و فروش</div>
                    <div class="option-btn" onclick="selectOption(this, 'transactionType')" data-value="پیش فروش">پیش فروش</div>
                    <div class="option-btn" onclick="selectOption(this, 'transactionType')" data-value="اجاره">اجاره</div>
                </div>
                <input type="hidden" id="transactionTypeInput" value="فروش">
            </div>
        </div>

        <!-- STEP 3: انتخاب نوع ملک (با اضافه شدن باغ، اداری و تجاری) -->
        <div class="step-content" id="step3">
            <h2 class="step-title">نوع ملک</h2>
            <p class="step-subtitle">نوع ملک خود را انتخاب کنید.</p>
            <div class="form-group"><label>نوع ملک</label>
                <div class="options-group" id="propertyType">
                    <a href="register-apartment.php" class="option-btn">آپارتمان</a>
                    <a href="register-villa.php" class="option-btn">ویلا</a>
                    <a href="register-land.php" class="option-btn">زمین</a>
                    <a href="register-garden.php" class="option-btn">باغ</a>
                    <a href="register-office.php" class="option-btn">اداری</a>
                    <a href="register-commercial.php" class="option-btn">تجاری</a>
                </div>
            </div>
        </div>

        <!-- دکمه‌های ناوبری - درست مانند کد ارسالی -->
        <div class="bottom-actions">
            <button type="button" class="btn-secondary" id="prevBtn" onclick="changeStep(-1)" style="display:none;">مرحله قبل</button>
            <button type="button" class="btn-primary-full" id="nextBtn" onclick="changeStep(1)">مرحله بعد</button>
        </div>
    </form>
</div>

<script>
    let currentStep = 1;
    const totalSteps = 3;
    let isTelegramUser = false;

    // ==============================================
    // شناسایی کاربر: اول تلگرام، اگر نبود، شماره واقعی کاربر
    // (قبلاً وقتی سایت داخل تلگرام باز نمی‌شد، یک آی‌دی و شماره‌ی
    // ساختگی و ثابت برای همه ثبت می‌شد؛ به همین دلیل همه یک نفر
    // به‌حساب می‌آمدند. حالا اگر تلگرام نبود، از کاربر شماره‌ی واقعی
    // می‌خواهیم و آن را برای بازدیدهای بعدی هم نگه می‌داریم.)
    // ==============================================
    function loadTelegramUser() {
        try {
            const tg = window.Telegram && window.Telegram.WebApp;
            const user = tg && tg.initDataUnsafe && tg.initDataUnsafe.user;
            if (user && user.id) {
                isTelegramUser = true;
                document.getElementById('regTelegramId').value = user.id;
                if (user.phone_number) {
                    document.getElementById('regPhone').value = user.phone_number;
                    document.getElementById('requestContactBtn').style.display = 'none';
                }
            }
        } catch (e) {}

        if (!isTelegramUser) {
            // اگر قبلاً همین مرورگر شماره‌اش را وارد کرده، دوباره نپرس
            const savedPhone = localStorage.getItem('melkino_user_phone');
            if (savedPhone) {
                document.getElementById('regPhone').value = savedPhone;
            }
            document.getElementById('requestContactBtn').style.display = 'none';
        }
    }

    function requestPhone() {
        try {
            window.Telegram.WebApp.requestContact();
        } catch (e) {}
    }

    function isValidIranianPhone(value) {
        const normalized = value.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).trim();
        return /^09\d{9}$/.test(normalized);
    }

    function selectOption(el, groupId) {
        const parent = document.getElementById(groupId);
        parent.querySelectorAll('.option-btn').forEach(b => b.classList.remove('selected'));
        el.classList.add('selected');
        if (groupId === 'regGender') document.getElementById('genderInput').value = el.getAttribute('data-value') || el.innerText;
        if (groupId === 'transactionType') document.getElementById('transactionTypeInput').value = el.getAttribute('data-value') || el.innerText;
    }

    function changeStep(direction) {
        if (currentStep === 1 && direction === 1) {
            const lastName = document.getElementById('regLastName').value.trim();
            const phone = document.getElementById('regPhone').value.trim();

            if (!lastName) {
                alert('لطفاً نام خانوادگی خود را وارد کنید.');
                return;
            }

            if (!isTelegramUser && !isValidIranianPhone(phone)) {
                alert('لطفاً یک شماره موبایل معتبر وارد کنید (مثلاً ۰۹۱۲۳۴۵۶۷۸۹).');
                return;
            }

            if (!isTelegramUser) {
                localStorage.setItem('melkino_user_phone', phone);
            }
        }

        sessionStorage.setItem('reg_telegram_id', document.getElementById('regTelegramId').value);
        sessionStorage.setItem('reg_gender', document.getElementById('genderInput').value);
        sessionStorage.setItem('reg_last_name', document.getElementById('regLastName').value);
        sessionStorage.setItem('reg_phone', document.getElementById('regPhone').value);
        sessionStorage.setItem('reg_transaction_type', document.getElementById('transactionTypeInput').value);

        if (currentStep === totalSteps && direction === 1) {
            return;
        }

        document.getElementById(`step${currentStep}`).classList.remove('active');
        currentStep += direction;
        if (currentStep < 1) currentStep = 1;
        if (currentStep > totalSteps) currentStep = totalSteps;
        document.getElementById(`step${currentStep}`).classList.add('active');

        document.getElementById('stepCounter').innerText = currentStep;
        document.getElementById('prevBtn').style.display = (currentStep === 1) ? 'none' : 'flex';
        
        if (currentStep === totalSteps) {
            document.getElementById('nextBtn').style.display = 'none';
        } else {
            document.getElementById('nextBtn').style.display = 'flex';
            document.getElementById('nextBtn').innerText = (currentStep === totalSteps - 1) ? 'انتخاب نوع ملک' : 'مرحله بعد';
        }

        document.getElementById('mainContent').scrollTop = 0;
    }

    document.addEventListener('DOMContentLoaded', function() {
        loadTelegramUser();
        document.getElementById('step1').classList.add('active');
        document.getElementById('prevBtn').style.display = 'none';
        document.getElementById('nextBtn').innerText = 'مرحله بعد';
        document.getElementById('stepCounter').innerText = '۱';
    });
</script>
<?php require_once 'footer.php'; ?>