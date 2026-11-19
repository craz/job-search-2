import { JobBusyBanner } from './shared/JobBusyBanner';
import { LiveReload } from './shared/LiveReload';
import { ScheduleStatusPoll } from './shared/ScheduleStatusPoll';

new LiveReload().boot();
JobBusyBanner.boot();
ScheduleStatusPoll.fromDocument();
