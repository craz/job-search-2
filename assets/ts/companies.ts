import { JobBusyBanner } from './shared/JobBusyBanner';
import { LiveReload } from './shared/LiveReload';

new LiveReload().boot();
JobBusyBanner.boot();
