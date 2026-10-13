<?php

namespace Phattarachai\RemotePrompt\Prompts;

use Laravel\Prompts\Key;

/**
 * A palette split into tabs (Targets | Actions | Setup). Tab / Shift+Tab switch tabs,
 * and so do ← → while the search box is empty. The query is kept across tabs.
 */
class TabbedSearch extends PaletteSearch
{
    /**
     * @param  list<array{title: string, entries: array<string, array{label: string, search: string}>}>  $tabs
     */
    public function __construct(private array $tabs, private int $active = 0, string $hint = '', int $initial = 0)
    {
        parent::__construct(
            label: '',
            options: fn (string $query) => Fuzzy::filter($this->tabs[$this->active]['entries'], $query),
            hint: $hint,
            initial: $initial,
        );

        $this->label = $this->tabStrip();

        $this->on('key', fn (string $key) => match (true) {
            $key === Key::TAB => $this->switchTab(1),
            $key === Key::SHIFT_TAB => $this->switchTab(-1),
            Key::oneOf([Key::RIGHT, Key::RIGHT_ARROW], $key) && $this->typedValue === '' => $this->switchTab(1),
            Key::oneOf([Key::LEFT, Key::LEFT_ARROW], $key) && $this->typedValue === '' => $this->switchTab(-1),
            default => null,
        });
    }

    public function activeTab(): int
    {
        return $this->active;
    }

    private function switchTab(int $direction): void
    {
        $count = count($this->tabs);

        if ($count < 2) {
            return;
        }

        $this->active = ($this->active + $direction + $count) % $count;
        $this->label = $this->tabStrip();
        $this->matches = null;
        $this->firstVisible = 0;
        $this->highlight($this->matches() === [] ? null : 0);
    }

    /**
     * " Branches   Actions " with the active tab in reverse video.
     */
    private function tabStrip(): string
    {
        if (count($this->tabs) === 1) {
            return $this->tabs[0]['title'];
        }

        $titles = array_map(
            fn (array $tab, int $index) => $index === $this->active
                ? "\e[7m {$tab['title']} \e[27m"
                : "\e[2m {$tab['title']} \e[22m",
            $this->tabs,
            array_keys($this->tabs),
        );

        return implode(' ', $titles);
    }
}
