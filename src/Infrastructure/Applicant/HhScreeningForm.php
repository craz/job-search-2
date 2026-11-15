<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Domain\Applicant\ScreeningOption;
use JobSearch\Domain\Applicant\ScreeningTask;
use Playwright\Page\PageInterface;

/**
 * DOM анкеты HH: сбор вопросов и заполнение radio/textarea.
 */
final class HhScreeningForm
{
    /**
     * @return list<ScreeningTask>
     */
    public function collectTasks(PageInterface $page): array
    {
        $raw = $page->evaluate(<<<'JS'
() => {
  const optionLabel = (el) => {
    const lab = el.closest('label');
    if (lab && (lab.innerText || '').trim()) return (lab.innerText || '').trim();
    const cell = el.closest('[data-qa="cell"]');
    if (cell && (cell.innerText || '').trim()) return (cell.innerText || '').trim();
    return (el.parentElement?.innerText || '').trim();
  };

  // Magritte quiz: каждый вопрос в [data-qa=task-body] + [data-qa=task-question].
  const bodies = [...document.querySelectorAll('[data-qa="task-body"]')];
  if (bodies.length) {
    const items = [];
    for (const body of bodies) {
      const qEl = body.querySelector('[data-qa="task-question"]');
      const q = ((qEl && qEl.innerText) || '').trim().replace(/\u00a0/g, ' ');
      const radios = [...body.querySelectorAll('input[type="radio"], input[type="checkbox"]')];
      if (radios.length) {
        const name = (radios[0].name || '').replace(/_text$/, '');
        if (!name) continue;
        items.push({
          name,
          question: q,
          options: radios.map((r) => ({
            value: r.value,
            label: optionLabel(r),
            type: r.type,
          })),
        });
        continue;
      }
      const ta = body.querySelector('textarea[name^="task_"]');
      if (!ta) continue;
      items.push({
        name: (ta.name || '').replace(/_text$/, ''),
        question: q,
        options: [],
      });
    }
    return items;
  }

  // Fallback: старые формы без task-body.
  const items = [];
  const names = [...new Set([...document.querySelectorAll('input[name^="task_"], textarea[name^="task_"]')]
    .map(el => el.name.replace(/_text$/, '')))];
  for (const name of names) {
    const inputs = [...document.querySelectorAll(
      `input[name="${name}"], textarea[name="${name}_text"], textarea[name="${name}"]`
    )];
    if (!inputs.length) continue;
    let q = '';
    let cur = inputs[0];
    for (let i = 0; i < 12; i++) {
      cur = cur.parentElement;
      if (!cur) break;
      const t = (cur.innerText || '').trim();
      if (t.length > 20 && t.length < 2000) {
        const lines = t.split('\n').map(s => s.trim()).filter(Boolean);
        q = lines[0] || '';
        for (const line of lines.slice(0, 10)) {
          if (line.length > 20 && !/^(да|нет|все есть|hadoop|kafka)/i.test(line)) {
            q = line;
            break;
          }
        }
        break;
      }
    }
    items.push({
      name,
      question: q,
      options: inputs
        .filter(i => i.type === 'radio' || i.type === 'checkbox')
        .map(r => ({
          value: r.value,
          label: optionLabel(r),
          type: r.type,
        })),
    });
  }
  return items;
}
JS);

        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $task = ScreeningTask::fromArray($row);
            if ($task->name !== '') {
                $out[] = $task;
            }
        }

        return $out;
    }

    public function pageHasTasks(PageInterface $page): bool
    {
        $n = $page->evaluate(<<<'JS'
() => document.querySelectorAll('textarea[name^="task_"], input[name^="task_"]').length
JS);

        return is_int($n) ? $n > 0 : (is_numeric($n) && (int) $n > 0);
    }

    public function clickLabel(PageInterface $page, string $name, array $options, string $pattern): bool
    {
        foreach ($options as $opt) {
            if (!$opt instanceof ScreeningOption) {
                continue;
            }
            if (preg_match('/' . $pattern . '/ui', $opt->label) !== 1) {
                continue;
            }
            $ok = $page->evaluate(
                <<<'JS'
({ name, value }) => {
  const el = document.querySelector(`input[name="${name}"][value="${CSS.escape(value)}"]`)
    || document.querySelector(`input[name="${name}"][value="${value}"]`);
  if (!el) return false;
  el.click();
  return true;
}
JS,
                ['name' => $name, 'value' => $opt->value],
            );
            if ($ok === true) {
                return true;
            }
        }

        return false;
    }

    public function yesNo(PageInterface $page, ScreeningTask $task, bool $yes): bool
    {
        return $this->clickLabel(
            $page,
            $task->name,
            $task->options,
            $yes ? '^да\\b' : '^нет\\b',
        );
    }

    public function fillText(PageInterface $page, string $name, string $text): bool
    {
        $ok = $page->evaluate(
            <<<'JS'
({ name, text }) => {
  const selectors = [
    `textarea[name="${name}_text"]`,
    `textarea[name="${name}"]`,
    `input[name="${name}"][type="text"]`,
    `input[name="${name}"][type="search"]`,
    `input[name="${name}"]:not([type])`,
    `input[name="${name}"][type=""]`,
  ];
  for (const sel of selectors) {
    const nodes = [...document.querySelectorAll(sel)];
    for (const el of nodes) {
      const typ = (el.getAttribute('type') || '').toLowerCase();
      if (typ === 'radio' || typ === 'checkbox') continue;
      const tag = el.tagName;
      if (tag === 'TEXTAREA' || typ === 'text' || typ === 'search' || typ === '') {
        el.focus();
        el.value = text;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }
    }
  }
  return false;
}
JS,
            ['name' => $name, 'text' => $text],
        );

        return $ok === true;
    }

    /**
     * Применить свободный ответ (буква опции / текст / «свой вариант»).
     *
     * @return bool true если удалось
     */
    public function applyAnswer(PageInterface $page, ScreeningTask $task, string $answer): bool
    {
        $ans = trim($answer);
        if ($ans === '' || in_array($ans, ['-', '—', '–', '.'], true)) {
            return true;
        }
        $name = $task->name;
        $opts = $task->options;
        $labels = array_map(static fn (ScreeningOption $o): string => $o->label, $opts);
        $letters = 'abcdefghijklmnopqrstuvwxyz';

        if (mb_strlen($ans) === 1 && str_contains($letters, mb_strtolower($ans)) && $labels !== []) {
            $idx = strpos($letters, mb_strtolower($ans));
            if ($idx !== false && isset($labels[$idx]) && $labels[$idx] !== '') {
                $lab = preg_quote(mb_substr($labels[$idx], 0, 80), '/');
                if ($this->clickLabel($page, $name, $opts, $lab)) {
                    return true;
                }
                if (isset($opts[$idx]) && $this->clickByValue($page, $name, $opts[$idx]->value)) {
                    return true;
                }
            }
        }

        if (ctype_digit($ans) && $labels !== []) {
            $idx = (int) $ans - 1;
            if (isset($labels[$idx]) && $labels[$idx] !== '') {
                $lab = preg_quote(mb_substr($labels[$idx], 0, 80), '/');
                if ($this->clickLabel($page, $name, $opts, $lab)) {
                    return true;
                }
            }
        }

        $ansLow = mb_strtolower($ans);
        foreach ($opts as $opt) {
            $lab = $opt->label;
            if ($lab === '') {
                continue;
            }
            $labLow = mb_strtolower($lab);
            if ($ansLow === $labLow || str_contains($labLow, $ansLow) || str_contains($ansLow, $labLow)) {
                $esc = preg_quote(mb_substr($lab, 0, 80), '/');
                if ($this->clickLabel($page, $name, $opts, $esc)) {
                    return true;
                }
                if ($this->clickByValue($page, $name, $opt->value)) {
                    return true;
                }
            }
        }

        if ($opts !== [] && $this->clickLabel($page, $name, $opts, 'свой\\s+вариант')) {
            if ($this->fillText($page, $name, $ans)) {
                return true;
            }
        }

        return $this->fillText($page, $name, $ans);
    }

    private function clickByValue(PageInterface $page, string $name, string $value): bool
    {
        $ok = $page->evaluate(
            <<<'JS'
({ name, value }) => {
  const el = document.querySelector(`input[name="${name}"][value="${value}"]`);
  if (!el) return false;
  el.click();
  return true;
}
JS,
            ['name' => $name, 'value' => $value],
        );

        return $ok === true;
    }

    public function clickSubmit(PageInterface $page): bool
    {
        $ok = $page->evaluate(<<<'JS'
() => {
  const btn = document.querySelector(
    '[data-qa="vacancy-response-submit-popup"], [data-qa="vacancy-response-popup-submit-button"]'
  );
  if (!btn || btn.disabled) return false;
  btn.click();
  return true;
}
JS);

        return $ok === true;
    }

    public function letterRequired(PageInterface $page): bool
    {
        $ok = $page->evaluate(<<<'JS'
() => {
  const root = document.querySelector('[data-qa="modal-overlay"]')
    || document.querySelector('[data-qa="vacancy-response-popup"]')
    || document.body;
  const text = ((root && root.innerText) || '').toLowerCase().replace(/\u00a0/g, ' ');
  const letterEl = root.querySelector(
    '[data-qa="vacancy-response-popup-form-letter-input"], textarea[name="letter"], textarea[data-qa*="letter"]'
  );
  const hint = /обязательн\w*.{0,48}сопроводительн|сопроводительн\w*.{0,48}обязательн|сопроводительное письмо\s*\*/i.test(text);
  const submit = root.querySelector(
    '[data-qa="vacancy-response-submit-popup"], [data-qa="vacancy-response-popup-submit-button"]'
  );
  if (letterEl && hint) return true;
  if (submit && submit.disabled && letterEl) return true;
  return false;
}
JS);

        return $ok === true;
    }
}
