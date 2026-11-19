import { FlashBanner } from './ui/FlashBanner';
import { JobBusyBanner } from './shared/JobBusyBanner';
import { LiveReload } from './shared/LiveReload';

FlashBanner.fromDocument()?.bind();
new LiveReload().boot();
JobBusyBanner.boot();
