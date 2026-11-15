# Планировщик (авто-задачи)

**Зачем:** гонять HH-джобы без клика.  
**Что делает:** демон читает `config/schedule.json`, по интервалу вызывает `php bin/hh.php <command>`.  
**Где код:** `ScheduleConfig`, `ScheduleStore`, `RunScheduledTasks`, `ScheduleDaemon`, `DescribeSchedule`, `hh.php schedule*`.

## Команды

| CLI | Смысл |
|--|--|
| `php bin/hh.php schedule` | демон (блокирует процесс; nohup / systemd) |
| `schedule-once` | один тик (удобно из cron) |
| `schedule-status` | JSON: running + last_run |
| `schedule-stop` | SIGTERM демону |

Лог: `data/cache/schedule.log`. Pid: `data/cache/schedule.pid` + FileCache `schedule_state`.

Live-статус: `GET /?job=schedule_status&format=json&task=chats|resume_views|vacancies` → `ScheduleStatusPoll` на главной / чатах / вакансиях.

## Задачи

Файл `config/schedule.json`:

| id | every | команда | смысл |
|--|--|--|--|
| `chats` | 600 с | `chats` | синк чатов HH |
| `resume_views` | 600 с | `resume-views` | просмотры резюме |
| `vacancies` | 1800 с | `vacancies` | новые suitable ≤**7 дней** → сразу DevAI; стоп: пустой SERP / возраст / maxIngest 40 (не по уже набранным apply/maybe) |

Новые задачи — строка в JSON (`id`, `command`, `every_seconds`, `enabled`). Белый список команд в `RunScheduledTasks`.

Если UI-job `running` — тик пропускает задачу (`job_busy`). Одна задача за тик.

## Старт

```bash
nohup php bin/hh.php schedule >> data/cache/schedule.log 2>&1 &
php bin/hh.php schedule-status
```

После правки `schedule.json` — `schedule-stop` и снова `schedule`.
