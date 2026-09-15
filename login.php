<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';

// اگر از قبل هویت معتبر داره، مستقیم بفرستش به پروفایل
$identity = melkinoCurrentIdentity();
if (!empty($identity['user_id'])) {
    header('Location: profile.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود به ملکینو</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script src="https://tapi.bale.ai/miniapp.js?3"></script>
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
<style>
    * { box-sizing: border-box; font-family: 'Vazirmatn', Tahoma, sans-serif; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        background: linear-gradient(160deg, #0D1413 0%, #122320 100%); color: #F3F4F6; padding: 20px;
    }
    .login-card {
        background: #16211F; border: 1px solid #223330; border-radius: 20px;
        padding: 32px 26px; max-width: 400px; width: 100%; text-align: center;
    }
    .login-card h1 { font-size: 20px; margin: 0 0 8px; }
    .login-card p { color: #A8B1AE; font-size: 14px; margin: 0 0 22px; line-height: 1.9; }
    .login-btn {
        width: 100%; padding: 14px; border-radius: 12px; border: none;
        font-size: 15px; font-weight: 700; cursor: pointer; margin-bottom: 12px;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .login-btn.telegram { background: #2AABEE; color: #fff; }
    .login-btn.bale { background: #2C4A7C; color: #fff; }
    .login-btn.phone { background: linear-gradient(135deg, #0E7C6E, #0B5D5B); color: #fff; }
    .login-divider { display: flex; align-items: center; gap: 10px; color: #6B7A76; font-size: 12px; margin: 18px 0; }
    .login-divider::before, .login-divider::after { content: ''; flex: 1; height: 1px; background: #223330; }
    .login-card input {
        width: 100%; padding: 14px; border-radius: 12px; border: 1px solid #2A3B37;
        background: #0D1413; color: #F3F4F6; font-size: 16px; text-align: center;
        direction: ltr; margin-bottom: 14px;
    }
    .login-msg { font-size: 13px; margin-bottom: 12px; min-height: 18px; }
    .login-msg.error { color: #F87171; }
    .login-msg.success { color: #4ADE80; }
    #phoneStep2 { display: none; }
</style>
</head>
<body>
    <div class="login-card">
        <div style="font-size:40px;margin-bottom:6px;">👋</div>
        <h1>ورود به ملکینو</h1>
        <p>یکی از روش‌های زیر را برای ورود انتخاب کن.</p>

        <button type="button" class="login-btn telegram" id="btnTelegram" style="display:none;" onclick="loginWithTelegram()">
            📨 ورود با تلگرام
        </button>

        <button type="button" class="login-btn bale" id="btnBale" style="display:none;" onclick="loginWithBale()">
            💬 ورود با بله
        </button>

        <div class="login-divider">یا با شماره موبایل</div>

        <div id="phoneStep1">
            <input type="tel" id="phoneInput" placeholder="09123456789" autofocus>
            <div class="login-msg" id="step1Msg"></div>
            <button type="button" class="login-btn phone" onclick="requestOtp()">دریافت کد ورود</button>
        </div>

        <div id="phoneStep2">
            <input type="text" id="codeInput" placeholder="کد ۶ رقمی" maxlength="6">
            <div class="login-msg" id="step2Msg"></div>
            <button type="button" class="login-btn phone" onclick="verifyOtp()">تأیید و ورود</button>
            <button type="button" class="login-btn" style="background:transparent;color:#7FBFB2;" onclick="backToStep1()">بازگشت / تغییر شماره</button>
        </div>
    </div>

<script>
var currentPhone = '';

document.addEventListener('DOMContentLoaded', function () {
    try {
        var params = new URLSearchParams(window.location.search);
        var prefillPhone = params.get('phone');
        if (prefillPhone && /^09\d{9}$/.test(prefillPhone)) {
            document.getElementById('phoneInput').value = prefillPhone;
        }
    } catch (e) {}

    try {
        if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initData) {
            document.getElementById('btnTelegram').style.display = 'flex';
        }
    } catch (e) {}
    try {
        if (window.Bale && window.Bale.WebApp && window.Bale.WebApp.initData) {
            document.getElementById('btnBale').style.display = 'flex';
        }
    } catch (e) {}
});

function loginWithTelegram() {
    var initData = window.Telegram.WebApp.initData;
    fetch('api/auth-telegram.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ init_data: initData })
    }).then(function (r) { return r.json(); })
      .then(function (data) {
          if (data.success) {
              window.location.href = 'profile.php';
          } else {
              alert(data.message || 'ورود با تلگرام ناموفق بود.');
          }
      })
      .catch(function () { alert('خطا در ارتباط با سرور.'); });
}

function loginWithBale() {
    var initData = window.Bale.WebApp.initData;
    fetch('api/auth-bale.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ init_data: initData })
    }).then(function (r) { return r.json(); })
      .then(function (data) {
          if (data.success) {
              window.location.href = 'profile.php';
          } else {
              alert(data.message || 'ورود با بله ناموفق بود.');
          }
      })
      .catch(function () { alert('خطا در ارتباط با سرور.'); });
}

function normalizePhone(value) {
    return value.replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); });
}

function requestOtp() {
    var phone = normalizePhone(document.getElementById('phoneInput').value.trim());
    var msg = document.getElementById('step1Msg');

    if (!/^09\d{9}$/.test(phone)) {
        msg.className = 'login-msg error';
        msg.textContent = 'شماره موبایل معتبر نیست.';
        return;
    }

    msg.className = 'login-msg';
    msg.textContent = 'در حال ارسال کد...';

    fetch('api/request-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone: phone })
    }).then(function (r) { return r.json(); })
      .then(function (data) {
          if (!data.success) {
              msg.className = 'login-msg error';
              msg.textContent = data.message || 'ارسال کد ناموفق بود.';
              return;
          }
          currentPhone = phone;
          document.getElementById('phoneStep1').style.display = 'none';
          document.getElementById('phoneStep2').style.display = 'block';
          var step2Msg = document.getElementById('step2Msg');
          step2Msg.className = 'login-msg success';
          step2Msg.textContent = data.message + (data.code ? ' — کد: ' + data.code : '');
      })
      .catch(function () {
          msg.className = 'login-msg error';
          msg.textContent = 'خطا در ارتباط با سرور.';
      });
}

function verifyOtp() {
    var code = document.getElementById('codeInput').value.trim();
    var msg = document.getElementById('step2Msg');

    if (!/^\d{6}$/.test(code)) {
        msg.className = 'login-msg error';
        msg.textContent = 'کد باید ۶ رقم باشد.';
        return;
    }

    fetch('api/verify-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone: currentPhone, code: code })
    }).then(function (r) { return r.json(); })
      .then(function (data) {
          if (!data.success) {
              msg.className = 'login-msg error';
              msg.textContent = data.message || 'کد نامعتبر است.';
              return;
          }
          try { localStorage.setItem('melkino_user_phone', currentPhone); } catch (e) {}
          window.location.href = 'profile.php';
      })
      .catch(function () {
          msg.className = 'login-msg error';
          msg.textContent = 'خطا در ارتباط با سرور.';
      });
}

function backToStep1() {
    document.getElementById('phoneStep1').style.display = 'block';
    document.getElementById('phoneStep2').style.display = 'none';
    document.getElementById('step1Msg').textContent = '';
}
</script>
</body>
</html>
