/* =========================================================
   ویجت مقایسه ملک‌ها (مشترک همه صفحات)
   - هر دکمه‌ای با [data-compare-add="AD-ID"] ملک را به مقایسه اضافه می‌کند
   - حباب شناور «⚖️ مقایسه (N)» به بخش مقایسه پروفایل لینک می‌دهد
   ========================================================= */
(function () {
    'use strict';

    if (window.__melkinoCompareWidgetLoaded) {
        return;
    }
    window.__melkinoCompareWidgetLoaded = true;

    /* ---------- Toast ---------- */

    let toastEl = null;
    let toastTimer = null;

    function ensureToast() {
        if (toastEl) {
            return toastEl;
        }
        toastEl = document.createElement('div');
        toastEl.id = 'melkinoCompareToast';
        toastEl.setAttribute('style', [
            'position:fixed',
            'bottom:26px',
            'right:50%',
            'transform:translateX(50%) translateY(20px)',
            'background:var(--surface,#fff)',
            'border:1px solid var(--border,#e5e7eb)',
            'border-radius:14px',
            'padding:12px 18px',
            'font-size:13px',
            'color:var(--text-primary,#111)',
            'box-shadow:0 12px 30px rgba(0,0,0,.18)',
            'opacity:0',
            'pointer-events:none',
            'transition:opacity .3s ease,transform .3s ease',
            'z-index:9999',
            'max-width:calc(100vw - 40px)',
            'text-align:center'
        ].join(';'));
        document.body.appendChild(toastEl);
        return toastEl;
    }

    function compareToast(message, ms) {
        const t = ensureToast();
        t.textContent = message;
        t.style.opacity = '1';
        t.style.transform = 'translateX(50%) translateY(0)';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            t.style.opacity = '0';
            t.style.transform = 'translateX(50%) translateY(20px)';
        }, ms || 3500);
    }

    /* ---------- Floating pill ---------- */

    let pillEl = null;

    function ensurePill() {
        if (pillEl) {
            return pillEl;
        }
        pillEl = document.createElement('a');
        pillEl.id = 'melkinoComparePill';
        pillEl.href = 'profile.php#compareSection';
        pillEl.setAttribute('style', [
            'position:fixed',
            'bottom:22px',
            'left:16px',
            'z-index:9000',
            'display:none',
            'align-items:center',
            'gap:8px',
            'background:linear-gradient(135deg,var(--primary,#0b5d5b),#0b5d5b)',
            'color:#fff',
            'border-radius:999px',
            'padding:11px 18px',
            'font-size:13px',
            'font-weight:800',
            'text-decoration:none',
            'box-shadow:0 10px 26px rgba(6,78,78,.35)'
        ].join(';'));
        document.body.appendChild(pillEl);
        return pillEl;
    }

    function updatePill(count) {
        // در خود صفحه پروفایل حباب لازم نیست
        if (window.location.pathname.indexOf('profile.php') !== -1) {
            return;
        }
        const pill = ensurePill();
        count = Number(count) || 0;
        if (count > 0) {
            pill.style.display = 'inline-flex';
            pill.textContent = '⚖️ مقایسه (' + count + ')';
        } else {
            pill.style.display = 'none';
        }
    }

    async function refreshCount() {
        try {
            const res = await fetch('compare.php?action=count', { cache: 'no-store' });
            const data = await res.json();
            if (data && data.success) {
                updatePill(data.count);
            }
        } catch (e) {
            /* ignore */
        }
    }

    /* ---------- Add to compare ---------- */

    async function addToCompare(adId, btn) {
        if (!adId) {
            return;
        }
        if (btn) {
            btn.disabled = true;
        }
        try {
            const body = new URLSearchParams();
            body.append('ad_id', adId);
            body.append('group', 'auto');
            const res = await fetch('compare.php?action=add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const data = await res.json().catch(function () { return null; });
            if (res.status === 401) {
                compareToast('🔑 برای مقایسه، اول وارد حساب شو.');
                return;
            }
            if (data && data.success) {
                if (data.already) {
                    compareToast('این ملک قبلاً در «' + (data.group_name || 'مقایسه') + '» هست.');
                } else {
                    compareToast('✅ به «' + (data.group_name || 'مقایسه') + '» اضافه شد. مدیریت در پروفایل ← مقایسه ملک‌ها');
                }
                if (btn) {
                    btn.classList.add('in-compare');
                }
                window.dispatchEvent(new CustomEvent('melkino:compare-changed'));
                refreshCount();
            } else {
                compareToast('❌ ' + ((data && data.message) || 'خطا در افزودن به مقایسه.'));
            }
        } catch (e) {
            compareToast('❌ خطا در ارتباط با سرور.');
        } finally {
            if (btn) {
                btn.disabled = false;
            }
        }
    }

    document.addEventListener('click', function (e) {
        const btn = e.target && e.target.closest
            ? e.target.closest('[data-compare-add]')
            : null;
        if (!btn) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        addToCompare(btn.getAttribute('data-compare-add'), btn);
    });

    window.addEventListener('melkino:compare-changed', function () {
        refreshCount();
    });

    /* ---------- Public API ---------- */

    window.melkinoCompareAdd = addToCompare;
    window.melkinoCompareRefreshCount = refreshCount;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshCount);
    } else {
        refreshCount();
    }
})();
