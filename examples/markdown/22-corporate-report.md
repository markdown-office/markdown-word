---
fields:
  client: Contoso Industrial
  period: Q2 2026
  reference: MER-QBR-2026-02
  prepared: 14 April 2026
  contract_value: €412,000 over three years
  renewal_date: Contract runs to 31 March 2029
---

# Executive summary

The quarter closed **above plan** for the third consecutive period, with revenue
growth driven almost entirely by the two renewals that were up for decision in
January.

> Retention remains the metric we watch most closely. It is the one number that
> cannot be bought back later with a discount.

## What moved

| Measure | Q1 | Q2 | Change |
| :-- | --: | --: | --: |
| Recurring revenue | €1.42M | €1.61M | **+13.4%** |
| Net retention | 104% | 109% | +5 pts |

Two things account for nearly all of the movement:

- **The Contoso renewal**, signed in week 3 after an evaluation that had stalled
  the previous quarter.
- **The platform migration**, which completed on 14 March.

## Delivery notes

The migration was deliberately unglamorous, and it is worth recording why it
finished early. The cutover was sequenced so that each tenant moved independently:

```bash
$ meridianctl migrate --tenant contoso --dry-run
==> resolving 41 collections, 1.2M documents
==> estimated duration 4m10s, rollback window 4m00s
```

### Risks we are carrying

1. **Single-region for the Contoso estate.** Their data is pinned to `eu-west-1`.
2. **Two contractors on the migration.** Both finish at the end of this month.