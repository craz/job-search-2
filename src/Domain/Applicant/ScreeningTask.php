<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

/**
 * Один вопрос анкеты работодателя на /applicant/vacancy_response.
 *
 * @phpstan-type OptionArray array{value: string, label: string, type?: string}
 */
final class ScreeningTask
{
    /**
     * @param list<ScreeningOption> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $question,
        public readonly array $options = [],
    ) {}

    public function hasYesNo(): bool
    {
        foreach ($this->options as $opt) {
            if (preg_match('/^(да|нет)\b/ui', $opt->label) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $options = [];
        $raw = $row['options'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $value = trim((string) ($opt['value'] ?? ''));
                $label = trim((string) ($opt['label'] ?? ''));
                if ($value === '' && $label === '') {
                    continue;
                }
                $options[] = new ScreeningOption(
                    $value !== '' ? $value : $label,
                    $label,
                    trim((string) ($opt['type'] ?? 'radio')) ?: 'radio',
                );
            }
        }

        return new self(
            trim((string) ($row['name'] ?? '')),
            trim((string) ($row['question'] ?? '')),
            $options,
        );
    }

    /** @return array{name: string, question: string, options: list<array{value: string, label: string, type: string}>} */
    public function toArray(): array
    {
        $opts = [];
        foreach ($this->options as $opt) {
            $opts[] = [
                'value' => $opt->value,
                'label' => $opt->label,
                'type' => $opt->type,
            ];
        }

        return [
            'name' => $this->name,
            'question' => $this->question,
            'options' => $opts,
        ];
    }
}
