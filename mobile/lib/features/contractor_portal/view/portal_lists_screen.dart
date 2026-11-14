import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/portal_cubit.dart';
import '../data/portal_models.dart';
import 'widgets/portal_kit.dart';

/// Every payment the company has counted as paid, newest first.
class PortalPaymentsScreen extends StatelessWidget {
  const PortalPaymentsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PortalCubit>(
      create: (context) => PortalCubit(context.read<AppServices>()),
      child: PortalPage(
        title: (context, snapshot) => AppLocalizations.of(context).portalPaymentsTitle,
        body: (context, snapshot) {
          final AppLocalizations l = AppLocalizations.of(context);
          if (snapshot.payments.isEmpty) {
            return <Widget>[EmptyLedger(title: l.portalNoPaymentsTitle, message: l.portalNoPaymentsMessage)];
          }
          return <Widget>[
            LedgerList(children: <Widget>[for (final PortalPayment payment in snapshot.payments) PortalPaymentTile(payment: payment)]),
          ];
        },
      ),
    );
  }
}

/// Every bill the company has approved, newest first.
class PortalBillsScreen extends StatelessWidget {
  const PortalBillsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PortalCubit>(
      create: (context) => PortalCubit(context.read<AppServices>()),
      child: PortalPage(
        title: (context, snapshot) => AppLocalizations.of(context).portalBillsTitle,
        body: (context, snapshot) {
          final AppLocalizations l = AppLocalizations.of(context);
          if (snapshot.bills.isEmpty) {
            return <Widget>[EmptyLedger(title: l.portalNoBillsTitle, message: l.portalNoBillsMessage)];
          }
          return <Widget>[
            LedgerList(
              children: <Widget>[
                for (final PortalBill bill in snapshot.bills)
                  PortalBillTile(bill: bill, onTap: () => context.push(Routes.portalBill(bill.id))),
              ],
            ),
          ];
        },
      ),
    );
  }
}
