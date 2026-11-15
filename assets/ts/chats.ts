import { LiveReload } from './shared/LiveReload';
import { ScheduleStatusPoll } from './shared/ScheduleStatusPoll';

new LiveReload().boot();
ScheduleStatusPoll.fromDocument();
