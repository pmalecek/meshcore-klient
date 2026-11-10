// Načte knihovnu meshcore.js (ES moduly) a zpřístupní ji klasickému skriptu ble.js.
import * as MeshCore from './vendor/meshcore/index.js';

window.MC_LIB = MeshCore;
window.dispatchEvent(new Event('mc-lib'));
