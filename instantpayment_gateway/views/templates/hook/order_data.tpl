{if isset($paygate) && $paygate}
<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-header-title">
      <i class="material-icons">account_balance_wallet</i> PayGate Transaction Details
    </h3>
  </div>
  <div class="card-body">
    <table class="table table-striped">
      <tr><th>Address In</th><td>{$paygate.address_in}</td></tr>
      {if $paygate.polygon_address_in}
        <tr><th>Polygon Wallet</th><td>{$paygate.polygon_address_in}</td></tr>
      {/if}
      <tr><th>IPN Token</th><td>{$paygate.ipn_token}</td></tr>
      <tr><th>Value Coin</th><td>{$paygate.value_coin}</td></tr>
      <tr><th>TXID In</th><td>{$paygate.txid_in}</td></tr>
      <tr><th>TXID Out</th><td>{$paygate.txid_out}</td></tr>
      <tr><th>Created</th><td>{$paygate.created_at}</td></tr>
      <tr><th>Updated</th><td>{$paygate.updated_at}</td></tr>
    </table>
  </div>
</div>
{/if}
