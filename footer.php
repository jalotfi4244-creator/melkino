<?php
/* footer.php */
?>

        <nav class="bottom-nav">
            <a href="home.php" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                <span>خانه</span>
            </a>

            <a href="search.php" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <span>جستجو</span>
            </a>

            <a href="register-step1.php" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M12 5v14"></path>
                    <path d="M5 12h14"></path>
                </svg>
                <span>ثبت</span>
            </a>

            <a href="favorites.php" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                </svg>
                <span>علاقه‌ها</span>
            </a>

            <a href="profile.php" class="nav-item">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                <span>پروفایل</span>
            </a>
        </nav>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof updateBadge === 'function') {
            updateBadge();
        }
        if (typeof updateThemeButton === 'function') {
            updateThemeButton();
        }
    });
    </script>

    <!-- =========================================================
         تزریق تبلیغ‌ها بین کارت‌ها در صفحاتی که با JS رندر می‌شوند
         ========================================================= -->
    <script>
    (function () {
        var page = (location.pathname.split('/').pop() || '').toLowerCase();
        var placementMap = {
            'properties.php': 'properties',
            'search-results.php': 'search',
            'vip.php': 'vip',
            'home.php': 'home',
            'index.php': 'home'
        };
        var placement = placementMap[page];
        if (!placement || placement === 'home') return; // خانه به‌صورت سروری تزریق می‌شود

        var selectors = ['#propertiesList', '#adsListContainer', '#vipList', '#adsList', '[data-ads-container]'];

        function findContainer() {
            for (var i = 0; i < selectors.length; i++) {
                var el = document.querySelector(selectors[i]);
                if (el) return el;
            }
            return null;
        }

        function buildSlot(promo) {
            var slot = document.createElement('div');
            slot.setAttribute('data-promo-slot', '');
            slot.setAttribute('data-promo-id', String(promo.id));
            slot.innerHTML = promo.html;

            var link = slot.querySelector('.promo-cta');
            if (link) {
                // ثبت کلیک: ابتدا به promotion-click.php می‌رویم و سپس به مقصد
                var destination = link.getAttribute('href') || '';
                if (destination && destination.indexOf('#') !== 0) {
                    link.setAttribute('href', 'promotion-click.php?id=' + encodeURIComponent(promo.id));
                    link.setAttribute('target', '_blank');
                    link.setAttribute('rel', 'noopener nofollow');
                }
            }
            return slot;
        }

        function inject(container, promos) {
            if (!container || !promos || !promos.length) return;

            // پاک‌سازی تزریق‌های قبلی (برای رندر دوباره بعد از فیلتر)
            var old = container.querySelectorAll('[data-promo-slot]');
            for (var i = 0; i < old.length; i++) old[i].remove();

            var children = Array.prototype.slice.call(container.children);
            var cardIndex = 0;

            for (var c = 0; c < children.length; c++) {
                var card = children[c];
                if (card.getAttribute('data-promo-slot') !== null) continue;
                cardIndex++;

                for (var p = 0; p < promos.length; p++) {
                    var promo = promos[p];
                    var first = Math.max(1, promo.position_after || 3);
                    var repeat = promo.repeat_every || 0;
                    var show = (cardIndex === first);
                    if (!show && repeat > 0 && cardIndex > first) {
                        show = ((cardIndex - first) % repeat) === 0;
                    }
                    if (show && card.parentNode) {
                        card.parentNode.insertBefore(buildSlot(promo), card.nextSibling);
                    }
                }
            }
        }

        fetch('promotions-feed.php?placement=' + encodeURIComponent(placement), { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success || !data.promotions || !data.promotions.length) return;

                var promos = data.promotions;
                var container = findContainer();
                if (container) inject(container, promos);

                // رندر دوباره بعد از فیلتر/اسکرول
                var timer = null;
                var observer = new MutationObserver(function () {
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        var current = findContainer();
                        if (current) inject(current, promos);
                    }, 400);
                });
                if (container) {
                    observer.observe(container, { childList: true, subtree: false });
                }
            })
            .catch(function () { /* تبلیغ اختیاری است */ });
    })();
    </script>

</body>
</html>