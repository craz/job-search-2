import { UiApp } from './ui/UiApp';
import { JobBusyBanner } from './shared/JobBusyBanner';
import { ScheduleStatusPoll } from './shared/ScheduleStatusPoll';

new UiApp().boot();
JobBusyBanner.boot();
ScheduleStatusPoll.fromDocument();
