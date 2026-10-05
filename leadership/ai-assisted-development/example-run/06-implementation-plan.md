---
project: browser-demo
issue: CON-DEMO-003
current_step: null
total_steps: 3
steps_done: 2
status: in_progress
logs_in_git: true
handoff_fix: null
---

# Browser demo development plan

## Прогресс

| # | Шаг | Статус |
|---|---|---|
| 1 | Первый уровень и карточка продукта | [done] |
| 2 | Боевые механики | [in_review] |
| 3 | Второй уровень | [done] |

### Шаг 1 [done]

Создать первый уровень, управление desktop/mobile и переход из каталога.

Verify: syntax, browser smoke, catalog navigation.

### Шаг 2 [in_review]

Добавить ближнюю и дальнюю атаку. Технические проверки пройдены; пользовательское ощущение боя ожидает отдельной человеческой оценки.

Verify: syntax, runtime smoke, manual play/feel review.

### Шаг 3 [done]

Добавить отдельный второй уровень: новый маршрут, семь целей, противник, завершение, рестарт и переход из первого уровня. Сохранить первый уровень и каталог.

Verify: syntax; настоящий Chromium desktop/mobile; полный маршрут без телепортации; бой; рестарт; регрессия первого уровня; отсутствие browser errors и horizontal overflow.
