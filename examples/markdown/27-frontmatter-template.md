---
template_file: ../templates/invoice.dotx
fields:
  number: INV-2026-0118
  customer: Northwind Ltd
---

# Quotation

Thank you for the enquiry. This is what we propose, and what it costs, for the
work described below. It holds for **30 days** from the date on the letterhead.

## Scope

| Item | Days | Rate |
| --- | ---: | ---: |
| Discovery workshop | 3 | 1,150.00 |
| Migration, phase one | 12 | 980.00 |
| Migration, phase two | 9 | 980.00 |
| Handover and training | 4 | 1,150.00 |

Phase two begins once phase one has run uneventfully for two weeks. That is the
only dependency between the two, and it is deliberate: we would rather absorb a
delay than have you hold the whole programme for one.

> Discovery is billed whether or not you go on to the migration. It is two days of
> somebody senior, and it is the part most likely to change the number above.

## Assumptions

1. The estate is the 41 collections the survey found, and no more.
2. `eu-west-1` remains the only region in scope.
3. Access is through the existing break-glass account, not a new one.

### What we would need from you

- Read access to the staging environment for the duration.
- A named owner for each of the 41 collections, by the end of discovery.
- A decision on the retention window, which we have assumed is 90 days.

> Late payment attracts interest at 4% per year.