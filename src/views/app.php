<?php
/**
 * Hlavní stránka klienta – sdílená pro režim Home Assistant ($mode = 'ha') a Bluetooth ($mode = 'ble').
 * Proměnné: $mode, $h, v režimu ha i $username a $csrf.
 */
?>
<!DOCTYPE html>
<html lang="cs" data-bs-theme="auto" data-mode="<?= $h($mode) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <?php if ($mode === 'ha' || !empty($username)): ?><meta name="csrf" content="<?= $h($csrf) ?>"><?php endif; ?>
    <?php if ($mode === 'ble'): ?><meta name="mc-analyzer" content="<?= !empty($analyzerOn) ? '1' : '0' ?>"><?php endif; ?>
    <meta name="theme-color" content="#0d6efd">
    <?php if ($mode === 'ble'): ?><base href="../">
    <link rel="manifest" href="ble/manifest.json">
    <?php endif; ?>
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= $mode === 'ble' ? 'ble/' : '' ?>apple-touch-icon.png">
    <title>MeshCore<?= $mode === 'ble' ? ' – Bluetooth' : '' ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
    <script src="<?= asset('assets/theme.js') ?>"></script>
</head>
<body>
<div id="app" class="app">

    <!-- ===== Levý panel: seznam konverzací ===== -->
    <aside class="sidebar border-end d-flex flex-column">
        <nav class="navbar bg-body-tertiary border-bottom px-3 py-2 flex-nowrap">
            <span class="navbar-brand d-flex align-items-center gap-2 me-auto">
                <i class="bi <?= $mode === 'ble' ? 'bi-bluetooth' : 'bi-broadcast-pin' ?> text-primary"></i> MeshCore
            </span>
            <span id="status-dot" class="status-dot me-2" title="Stav připojení"></span>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Menu">
                    <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php if ($mode === 'ha'): ?>
                    <li><h6 class="dropdown-header"><?= $h((string) $username) ?></h6></li>
                    <?php else: ?>
                    <li><h6 class="dropdown-header"><?= !empty($username) ? $h((string) $username) . ' · Bluetooth' : 'Bluetooth companion' ?></h6></li>
                    <li><button class="dropdown-item" id="btn-ble-connect"><i class="bi bi-bluetooth me-2"></i>Připojit companion</button></li>
                    <li><button class="dropdown-item d-none" id="btn-ble-disconnect"><i class="bi bi-x-circle me-2"></i>Odpojit companion</button></li>
                    <li><button class="dropdown-item" id="btn-ble-settings"><i class="bi bi-sliders me-2"></i>Nastavení companionu</button></li>
                    <li><button class="dropdown-item d-none" id="btn-ble-install"><i class="bi bi-download me-2"></i>Nainstalovat jako aplikaci</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php endif; ?>
                    <li><button class="dropdown-item" id="btn-status"><i class="bi bi-activity me-2"></i>Stav připojení</button></li>
                    <li><button class="dropdown-item" id="btn-appearance"><i class="bi bi-palette me-2"></i>Vzhled</button></li>
                    <li><button class="dropdown-item" id="btn-retention"><i class="bi bi-clock-history me-2"></i>Historie zpráv</button></li>
                    <li><button class="dropdown-item" id="btn-notify"><i class="bi bi-bell me-2"></i>Povolit notifikace</button></li>
                    <?php if ($mode === 'ha'): ?>
                    <li><button class="dropdown-item" id="btn-password"><i class="bi bi-key me-2"></i>Heslo k přihlášení</button></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header">Advert</h6></li>
                    <li><button class="dropdown-item btn-advert" data-flood="0" title="Zero-hop – uslyší jen přímí sousedé"><i class="bi bi-broadcast me-2"></i>Odeslat advert (local)</button></li>
                    <li><button class="dropdown-item btn-advert" data-flood="1" title="Šíří se přes repeatery celou sítí"><i class="bi bi-broadcast-pin me-2"></i>Odeslat advert (flood)</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <div class="dropdown-item-text">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="opt-hide-dups" checked>
                                <label class="form-check-label text-nowrap" for="opt-hide-dups">Skrývat duplicitní zprávy</label>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <?php if ($mode === 'ha'): ?>
                    <li><a class="dropdown-item" href="ble/"><i class="bi bi-bluetooth me-2"></i>Připojit přes Bluetooth</a></li>
                    <li>
                        <form method="post" action="logout.php" class="m-0">
                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                            <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Odhlásit</button>
                        </form>
                    </li>
                    <?php else: ?>
                    <li><a class="dropdown-item" href="./"><i class="bi bi-house me-2"></i>Klient přes Home Assistant</a></li>
                    <?php if (!empty($username)): ?>
                    <li>
                        <form method="post" action="logout.php" class="m-0">
                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="next" value="ble">
                            <button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Odhlásit</button>
                        </form>
                    </li>
                    <?php else: ?>
                    <li><a class="dropdown-item" href="login.php?next=ble"><i class="bi bi-box-arrow-in-right me-2"></i>Přihlásit (historie i na serveru)</a></li>
                    <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <?php if ($mode === 'ble'): ?>
        <div id="ble-bar" class="ble-bar border-bottom px-3 py-2 small d-flex align-items-center gap-2">
            <i class="bi bi-bluetooth"></i><span class="flex-grow-1" id="ble-bar-text">Companion není připojen</span>
            <button type="button" class="btn btn-sm btn-primary" id="ble-bar-btn">Připojit</button>
        </div>
        <?php endif; ?>
        <div class="p-2 border-bottom">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="search" name="mc-search" class="form-control" placeholder="Hledat v kontaktech a zprávách…" autocomplete="off" data-lpignore="true" data-1p-ignore>
            </div>
        </div>

        <ul class="nav nav-pills nav-fill tabs-bar px-1 pt-2" id="tabs">
            <li class="nav-item"><button class="nav-link active" data-tab="chats"><i class="bi bi-chat-dots"></i><span>Zprávy</span><span class="badge rounded-pill text-bg-danger d-none" id="badge-chats"></span></button></li>
            <li class="nav-item"><button class="nav-link" data-tab="channels"><i class="bi bi-hash"></i><span>Kanály</span><span class="badge rounded-pill text-bg-danger d-none" id="badge-channels"></span></button></li>
            <li class="nav-item"><button class="nav-link" data-tab="favorites"><i class="bi bi-star"></i><span>Oblíbené</span><span class="badge rounded-pill text-bg-danger d-none" id="badge-favorites"></span></button></li>
            <li class="nav-item"><button class="nav-link" data-tab="contacts"><i class="bi bi-person-lines-fill"></i><span>Kontakty</span></button></li>
            <li class="nav-item"><button class="nav-link" data-tab="devices"><i class="bi bi-hdd-network"></i><span>Zařízení</span><span class="badge rounded-pill text-bg-warning d-none" id="badge-devices"></span></button></li>
            <li class="nav-item"><button class="nav-link" data-tab="map"><i class="bi bi-map"></i><span>Mapa</span></button></li>
        </ul>

        <div id="conv-list" class="list-group list-group-flush flex-grow-1 overflow-auto mt-2"></div>
        <div id="sidebar-footer" class="sidebar-footer border-top small text-body-secondary px-3 py-1 text-truncate d-none"></div>
    </aside>

    <!-- ===== Pravý panel: chat ===== -->
    <main class="chat d-flex flex-column">
        <div id="chat-empty" class="flex-grow-1 d-flex flex-column align-items-center justify-content-center text-body-secondary">
            <i class="bi bi-chat-dots display-4 mb-2"></i>
            <p>Vyber konverzaci</p>
        </div>

        <div id="map-view" class="d-none d-flex flex-column h-100">
            <header class="navbar bg-body-tertiary border-bottom px-2 py-2 flex-nowrap">
                <button class="btn btn-link text-body d-md-none me-1 px-2" id="btn-map-back" aria-label="Zpět na seznam">
                    <i class="bi bi-arrow-left fs-5"></i>
                </button>
                <i class="bi bi-map fs-5 mx-2 text-primary"></i>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold">Mapa sítě</div>
                    <div class="small text-body-secondary text-truncate" id="map-subtitle"></div>
                </div>
                <button class="btn btn-sm btn-link text-body px-2" id="btn-map-fit" title="Zobrazit vše"><i class="bi bi-arrows-angle-expand fs-5"></i></button>
            </header>
            <div id="net-map" class="net-map flex-grow-1"></div>
        </div>

        <div id="chat-view" class="d-none d-flex flex-column h-100">
            <header class="navbar bg-body-tertiary border-bottom px-2 py-2 flex-nowrap">
                <button class="btn btn-link text-body d-md-none me-1 px-2" id="btn-back" aria-label="Zpět">
                    <i class="bi bi-arrow-left fs-5"></i>
                </button>
                <div class="avatar me-2" id="chat-avatar"></div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate" id="chat-title"></div>
                    <div class="small text-body-secondary text-truncate" id="chat-subtitle"></div>
                </div>
                <button class="btn btn-sm btn-link text-body ms-1 px-2" id="btn-fav" aria-label="Oblíbené" title="Přidat do oblíbených">
                    <i class="bi bi-star fs-5"></i>
                </button>
                <button class="btn btn-sm btn-link text-body px-2" id="btn-conv-settings" aria-label="Nastavení chatu" title="Nastavení chatu (barva, pozadí, historie, region)">
                    <i class="bi bi-gear fs-5"></i>
                </button>
                <button class="btn btn-sm btn-link text-body px-2 d-none" id="btn-room" aria-label="Room server" title="Přihlásit do room serveru">
                    <i class="bi bi-door-open fs-5"></i>
                </button>
                <button class="btn btn-sm btn-link text-body px-2 d-none" id="btn-path" aria-label="Cesta" title="Cesta ke kontaktu">
                    <i class="bi bi-signpost-2 fs-5"></i>
                </button>
                <button class="btn btn-sm btn-link text-body px-2 d-none" id="btn-info" aria-label="Informace" title="Informace o kontaktu">
                    <i class="bi bi-info-circle fs-5"></i>
                </button>
            </header>

            <div id="messages" class="messages flex-grow-1 overflow-auto px-2 px-sm-3 py-3">
                <div class="text-center d-none" id="load-more-wrap">
                    <button class="btn btn-sm btn-outline-secondary mb-3" id="load-more">Načíst starší</button>
                </div>
                <div id="message-list"></div>
            </div>

            <form id="composer" class="composer border-top bg-body-tertiary p-2 position-relative">
                <div id="mention-suggest" class="mention-suggest list-group shadow d-none"></div>
                <div class="input-group">
                    <button class="btn btn-outline-secondary px-3 d-none" type="button" id="btn-mention"
                            data-bs-toggle="dropdown" aria-expanded="false" title="Odpovědět uživateli">
                        <i class="bi bi-at"></i>
                    </button>
                    <ul class="dropdown-menu mention-menu" id="mention-menu"></ul>
                    <button class="btn btn-outline-secondary px-3" type="button" id="btn-share"
                            data-bs-toggle="dropdown" aria-expanded="false" title="Sdílet vizitku nebo polohu">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                    <ul class="dropdown-menu" id="share-menu">
                        <li><button type="button" class="dropdown-item" data-share="card"><i class="bi bi-person-vcard me-2"></i>Moje vizitka</button></li>
                        <li><button type="button" class="dropdown-item" data-share="pos-node"><i class="bi bi-geo-alt me-2"></i>Poloha companionu</button></li>
                        <li><button type="button" class="dropdown-item" data-share="pos-gps"><i class="bi bi-crosshair me-2"></i>Moje poloha (GPS zařízení)</button></li>
                    </ul>
                    <textarea id="msg-input" class="form-control" rows="1" placeholder="Napiš zprávu…" autocomplete="off"></textarea>
                    <button class="btn btn-primary px-3" type="submit" id="btn-send" aria-label="Odeslat">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
                <div class="d-flex justify-content-between small text-body-secondary mt-1 px-1">
                    <span id="send-error" class="text-danger"></span>
                    <span class="text-nowrap"><span id="traffic-info" class="traffic-info d-none me-2"></span><span id="char-count"></span></span>
                </div>
            </form>
        </div>
    </main>
</div>

<!-- Detail zprávy / kontaktu / stavu -->
<div class="modal fade" id="info-modal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="info-title"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body" id="info-body"></div>
        </div>
    </div>
</div>

<!-- Toasty -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>

<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<?php if ($mode === 'ble'): ?>
<script type="module" src="<?= asset('assets/ble-lib.js') ?>"></script>
<script src="<?= asset('assets/ble.js') ?>"></script>
<?php endif; ?>
<script src="<?= asset('assets/app.js') ?>"></script>
</body>
</html>
