import { FlashBanner } from './ui/FlashBanner';
import { LiveReload } from './shared/LiveReload';
import { ScheduleStatusPoll } from './shared/ScheduleStatusPoll';
import { ManualVerdictAction } from './vacancies/ManualVerdictAction';
import { RescoreAction } from './vacancies/RescoreAction';
import { VerdictPoll } from './vacancies/VerdictPoll';

new LiveReload().boot();
FlashBanner.fromDocument()?.bind();
ScheduleStatusPoll.fromDocument();
VerdictPoll.fromDocument()?.start();
RescoreAction.fromDocument()?.bind();
ManualVerdictAction.fromDocument()?.bind();
