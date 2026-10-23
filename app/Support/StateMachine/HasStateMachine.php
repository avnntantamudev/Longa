<?php

declare(strict_types=1);

namespace App\Support\StateMachine;

use Illuminate\Database\Eloquent\Model;

/**
 * Aplica transições de estado de forma segura:
 *  1. valida a transição na máquina de estados do enum;
 *  2. grava com UPDATE condicional (WHERE state = estado atual), para que um job e um pedido HTTP
 *     em simultâneo não apliquem os dois a sua transição;
 *  3. só depois atualiza o modelo em memória.
 *
 * @mixin Model
 */
trait HasStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes  colunas extra a gravar com a transição (ex.: responded_at)
     *
     * @throws InvalidTransition
     */
    public function transitionTo(StateEnum $to, array $attributes = []): static
    {
        /** @var StateEnum $from */
        $from = $this->getAttribute('state');
        $entity = class_basename($this);

        if (! $from->canTransitionTo($to)) {
            throw InvalidTransition::notAllowed($entity, $from, $to);
        }

        $now = $this->freshTimestamp();
        $values = [...$attributes, 'state' => $to->value];
        if ($this->usesTimestamps()) {
            $values[$this->getUpdatedAtColumn()] = $now;
        }

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('state', $from->value)
            ->update($values);

        if ($updated === 0) {
            throw InvalidTransition::concurrent($entity, $from, $to);
        }

        $this->forceFill([...$attributes, 'state' => $to]);
        if ($this->usesTimestamps()) {
            $this->setAttribute($this->getUpdatedAtColumn(), $now);
        }
        $this->syncOriginal();

        return $this;
    }
}
