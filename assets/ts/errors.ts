import { FlashBanner } from './ui/FlashBanner';
import { LiveReload } from './shared/LiveReload';

FlashBanner.fromDocument()?.bind();
new LiveReload().boot();
