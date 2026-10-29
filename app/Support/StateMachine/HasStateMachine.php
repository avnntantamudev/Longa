<?php

declare(strict_types=1);

namespace App\Support\StateMachine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Aplica transições de estado de forma segura:
 *  1. valida a transição na máquina de estados do enum;
 *  2. grava com UPDATE condicional (WHERE state = estado atual), para que um job e um pedido HTTP
 *     em simultâneo não apliquem os dois a sua transição;
 *  3. só depois atualiza o modelo em memória.
 *
 * O modelo pode acrescentar regras (transitionAllowed) e registar cada mudança (afterTransition); nesse caso
 * o UPDATE e o registo correm na mesma transação.
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

        if (! $from->canTransitionTo($to) || ! $this->transitionAllowed($from, $to)) {
            throw InvalidTransition::notAllowed($entity, $from, $to);
        }

        if (! method_exists($this, 'afterTransition')) {
            return $this->applyTransition($from, $to, $attributes);
        }

        return DB::transaction(function () use ($from, $to, $attributes) {
            $this->applyTransition($from, $to, $attributes);
            $this->afterTransition($from, $to, $attributes);

            return $this;
        });
    }

    /**
     * Regras extra do modelo, além das do enum (ex.: transições que dependem do modo de preço).
     */
    protected function transitionAllowed(StateEnum $from, StateEnum $to): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyTransition(StateEnum $from, StateEnum $to, array $attributes): static
    {
        $entity = class_basename($this);

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
