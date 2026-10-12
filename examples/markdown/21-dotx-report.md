# Executive summary

The quarter closed **above plan** for the third consecutive period, with revenue
growth driven almost entirely by the two renewals that were up for decision in
January. Operating margin improved by four points as the migration off the legacy
platform finished, and the support backlog is at its lowest since we began
measuring it.

> Retention remains the metric we watch most closely. It is the one number that
> cannot be bought back later with a discount.

## What moved

| Measure | Q1 | Q2 | Change |
| :-- | --: | --: | --: |
| Recurring revenue | €1.42M | €1.61M | **+13.4%** |
| Net retention | 104% | 109% | +5 pts |
| Support backlog | 31 | 12 | −19 |
| Time to first response | 6h 12m | 1h 48m | −71% |

Two things account for nearly all of the movement:

- **The Contoso renewal**, signed in week 3 after an evaluation that had stalled
  the previous quarter. It is a three-year term and removes the largest source of
  quarter-to-quarter variance from the forecast.
- **The platform migration**, which completed on 14 March. The legacy stack is now
  read-only and decommissioning begins next quarter.

## Delivery notes

The migration was deliberately unglamorous, and it is worth recording why it
finished early. The cutover was sequenced so that each tenant moved independently
and any of them could be rolled back within four minutes:

```bash
$ meridianctl migrate --tenant contoso --dry-run
==> resolving 41 collections, 1.2M documents
==> estimated duration 4m10s, rollback window 4m00s
==> no blocking issues found

$ meridianctl migrate --tenant contoso
==> migrating contoso … done in 3m41s
==> verifying checksums … 1,204,881 of 1,204,881 ok
```

### Risks we are carrying

1. **Single-region for the Contoso estate.** Their data is pinned to `eu-west-1`
   for contractual reasons, which leaves us with no warm failover. We have priced
   the cross-region replica and it is in front of the board in October.
2. **Two contractors on the migration.** Both finish at the end of this month.
   The handover documentation is complete but unreviewed by anyone who did not
   write it.

### Risks we have closed

- The `_legacy_billing` job was removed on 2 April, which retires the last writer to
  the `invoices` table outside the service itself.
- Certificate rotation is automated; the runbook that described doing it by hand no
  longer applies and has been withdrawn.

## Next quarter

The board asked for a plan against the flat case rather than the plan case, so the
numbers below assume **no new logos** and no expansion within the existing base:

- Complete cross-region replication for the Contoso estate.
- Retire the read-only legacy cluster and reclaim the associated spend.
- Convert the two remaining contractors to the support roster before the handover
  window closes.

> If we land one expansion in the quarter, the flat case becomes the floor rather
> than the expectation, which is the first time that has been true since 2022.

Further detail is in the [operations appendix](#operations-appendix), and the
methodology behind the retention figure is documented in
[`metrics/README.md`](https://example.invalid/metrics) — the same number has been
produced three ways now, which is the only reason anyone trusts it.