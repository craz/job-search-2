import { UiApp } from './ui/UiApp';
import { ScheduleStatusPoll } from './shared/ScheduleStatusPoll';

new UiApp().boot();
ScheduleStatusPoll.fromDocument();
