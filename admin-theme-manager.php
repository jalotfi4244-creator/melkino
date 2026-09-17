<?php
/**
|--------------------------------------------------------------------------
| تم و رنگ (پنل ادمین)
|--------------------------------------------------------------------------
| رابط کاربری این تب با endpoint موجودِ save_theme.php کار می‌کند:
|   GET  save_theme.php   دریافت رنگ‌های فعلی
|   POST save_theme.php   ذخیره رنگ‌ها (style.css + settings/theme.json)
|--------------------------------------------------------------------------
*/

$melkinoPresets = [
    ['key' => 'default',    'name' => 'ملکینو',        'desc' => 'پیش‌فرض',        'icon' => '🏝️', 'colors' => ['#064E4E', '#D4AF37', '#FAFAF7']],
    ['key' => 'emerald',    'name' => 'زمردی',          'desc' => 'سبز و طلایی',   'icon' => '💚', 'colors' => ['#065F46', '#C9A227', '#FAFAF7']],
    ['key' => 'onyx',       'name' => 'اونیکس',         'desc' => 'مشکی طلایی',    'icon' => '🖤', 'colors' => ['#1F2937', '#C9A227', '#FAFAF9']],
    ['key' => 'sapphire',   'name' => 'یاقوتی',         'desc' => 'آبی سلطنتی',    'icon' => '💙', 'colors' => ['#0F2E5C', '#C9A227', '#F8FAFC']],
    ['key' => 'champagne',  'name' => 'شامپاینی',       'desc' => 'کرم و طلایی',   'icon' => '🥂', 'colors' => ['#8A6A3B', '#D4AF37', '#FCFAF5']],
    ['key' => 'silver',     'name' => 'نقره‌ای',        'desc' => 'خاکستری براق',  'icon' => '🪙', 'colors' => ['#334155', '#BFA46F', '#F8FAFC']],
    ['key' => 'ocean',      'name' => 'اقیانوسی',       'desc' => 'آبی روشن',      'icon' => '🌊', 'colors' => ['#0369A1', '#D4AF37', '#F8FAFC']],
    ['key' => 'royal',      'name' => 'سلطنتی',         'desc' => 'بنفش اشرافی',   'icon' => '👑', 'colors' => ['#6D28D9', '#D4AF37', '#FAF8FF']],
    ['key' => 'sunset',     'name' => 'غروب',           'desc' => 'نارنجی گرم',    'icon' => '🌅', 'colors' => ['#C2410C', '#D4AF37', '#FFFAF5']],
    ['key' => 'forest',     'name' => 'جنگلی',          'desc' => 'سبز تیره',      'icon' => '🌲', 'colors' => ['#15803D', '#D4AF37', '#F7FAF7']],
];
?>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🎨 تم‌های آماده</span>
        <span class="admin-field-help" style="margin-inline-start:auto;">روی هر تم بزن تا روی کل سایت اعمال شود</span>
    </div>

    <div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
        <?php foreach ($melkinoPresets as $preset): ?>
            <button
                type="button"
                class="theme-preset-card"
                data-preset="<?= htmlspecialchars($preset['key'], ENT_QUOTES, 'UTF-8') ?>"
                onclick="applyThemePreset('<?= htmlspecialchars($preset['key'], ENT_QUOTES, 'UTF-8') ?>')"
            >
                <span class="theme-preset-swatches">
                    <?php foreach ($preset['colors'] as $color): ?>
                        <span class="theme-preset-swatch" style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>"></span>
                    <?php endforeach; ?>
                </span>
                <span class="theme-preset-name">
                    <span class="theme-preset-icon"><?= htmlspecialchars($preset['icon'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?= htmlspecialchars($preset['name'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="theme-preset-desc"><?= htmlspecialchars($preset['desc'], ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <style>
    .theme-preset-card {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 12px;
        border-radius: 16px;
        border: 1px solid var(--border);
        background: var(--surface);
        cursor: pointer;
        text-align: right;
        transition: transform .22s var(--ease), box-shadow .24s var(--ease), border-color .24s var(--ease);
        font-family: inherit;
    }
    .theme-preset-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: color-mix(in srgb, var(--gold) 55%, var(--border));
    }
    .theme-preset-card.is-active {
        border-color: var(--gold);
        box-shadow: var(--shadow-gold);
    }
    .theme-preset-swatches { display: flex; gap: 6px; }
    .theme-preset-swatch {
        width: 100%;
        height: 38px;
        border-radius: 10px;
        box-shadow: inset 0 0 0 1px rgba(0,0,0,.08);
    }
    .theme-preset-name {
        font-size: 13px;
        font-weight: 800;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .theme-preset-icon { font-size: 15px; }
    .theme-preset-desc { font-size: 11px; color: var(--text-muted); }
    </style>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🌓 ویرایش رنگ‌ها</span>
        <span id="themeStatus" class="admin-status-msg"></span>
    </div>

    <div style="padding:0 16px 16px;">
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <button type="button" class="btn-secondary" id="themeModeLight" style="padding:8px 16px;font-size:13px;" onclick="setThemeEditMode('light')">☀️ حالت روشن</button>
            <button type="button" class="btn-secondary" id="themeModeDark" style="padding:8px 16px;font-size:13px;" onclick="setThemeEditMode('dark')">🌙 حالت تاریک</button>
        </div>

        <div id="themeColorGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;"></div>

        <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;">
            <button type="button" class="btn-primary" onclick="saveThemeColors()">💾 ذخیره رنگ‌ها</button>
            <button type="button" class="btn-secondary" onclick="previewThemeColors()">👁️ پیش‌نمایش</button>
            <button type="button" class="btn-secondary" onclick="resetThemeColors()">↺ بازگشت به پیش‌فرض</button>
        </div>

        <div class="admin-field-help" style="margin-top:10px;">
            «پیش‌نمایش» رنگ‌ها را بدون ذخیره روی همین صفحه اعمال می‌کند؛ برای ثبت دائمی حتماً «ذخیره رنگ‌ها» را بزن.
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">🖼️ پیش‌نمایش زنده</span>
    </div>
    <div style="padding:0 16px 16px;">
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:16px;padding:18px;">
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:220px;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:14px;box-shadow:var(--shadow-md);">
                    <div style="height:96px;border-radius:12px;background:linear-gradient(135deg,rgba(212,175,55,.22),rgba(6,78,78,.12)),var(--bg-secondary);margin-bottom:12px;"></div>
                    <div style="font-size:14px;font-weight:800;color:var(--text-primary);margin-bottom:6px;">آپارتمان ۹۰ متری</div>
                    <div style="font-size:12px;color:var(--text-secondary);line-height:1.8;margin-bottom:10px;">شاهرود · خیابان ساحلی</div>
                    <div style="font-size:15px;font-weight:900;background:var(--gold-gradient);-webkit-background-clip:text;background-clip:text;color:transparent;">۳٬۰۰۰٬۰۰۰٬۰۰۰ تومان</div>
                </div>

                <div style="flex:1;min-width:220px;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:14px;display:flex;flex-direction:column;gap:10px;">
                    <button type="button" class="btn-primary" style="padding:10px;border:none;border-radius:12px;color:#fff;font-weight:800;cursor:default;">دکمه اصلی</button>
                    <button type="button" class="btn-secondary" style="padding:10px;border-radius:12px;cursor:default;">دکمه فرعی</button>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
                        <span style="background:var(--gold-bg);color:var(--gold-dark);font-size:11px;padding:5px 10px;border-radius:999px;font-weight:700;">ویژه</span>
                        <span style="background:var(--success-bg);color:var(--success);font-size:11px;padding:5px 10px;border-radius:999px;font-weight:700;">تأیید شده</span>
                        <span style="background:var(--danger-bg);color:var(--danger);font-size:11px;padding:5px 10px;border-radius:999px;font-weight:700;">رد شده</span>
                    </div>
                </div>
            </div>

            <div style="margin-top:14px;font-size:12px;color:var(--text-muted);line-height:1.9;">
                متن کم‌رنگ، حاشیه‌ها، دکمه‌ها و نشان‌ها را با رنگ‌های انتخابی همین‌جا می‌بینی.
            </div>
        </div>
    </div>
</div>
