<?php
declare(strict_types=1);

namespace JobSearch\Domain\Account;

/**
 * Снимок текущего пользователя HH (ответ GET /me, урезанный до нужных полей).
 */
final class Me
{
    public function __construct(
        public readonly int $http,
        public readonly ?string $id,
        public readonly ?bool $isApplicant,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $email,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromApi(int $http, array $data): self
    {
        return new self(
            http: $http,
            id: isset($data['id']) ? (string) $data['id'] : null,
            isApplicant: isset($data['is_applicant']) ? (bool) $data['is_applicant'] : null,
            firstName: isset($data['first_name']) ? (string) $data['first_name'] : null,
            lastName: isset($data['last_name']) ? (string) $data['last_name'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'http' => $this->http,
            'id' => $this->id,
            'is_applicant' => $this->isApplicant,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
        ];
    }

    public function isOk(): bool
    {
        return $this->http >= 200 && $this->http < 300;
    }
}
