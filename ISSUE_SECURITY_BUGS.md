# セキュリティ・バグ修正: XSS脆弱性とサニタイズ不足

## 概要

コードレビューにより、複数のセキュリティ問題とバグが発見されました。

## 🔴 重大度: 高

### 1. XSS脆弱性 - `SettingsPage.php:106`
```php
<h1><?php echo $this->ptname . ' ' . __('Settings', 'cptmc'); ?></h1>
```
**問題**: `$this->ptname` が `esc_html()` でエスケープされていない。

**修正案**:
```php
<h1><?php echo esc_html($this->ptname) . ' ' . esc_html__('Settings', 'cptmc'); ?></h1>
```

### 2. XSS脆弱性 - `SettingsPage.php:153`
```php
<?php echo $this->client->getProviderEndpoint($provider)->getDisplayText(); ?>
```
**問題**: `getDisplayText()` の返り値がエスケープされていない。

**修正案**:
```php
<?php echo esc_html($this->client->getProviderEndpoint($provider)->getDisplayText()); ?>
```

---

## 🟠 重大度: 中

### 3. HTML属性のエスケープ不足 - `SettingsPage.php`

以下の箇所で `esc_attr()` が使用されていない:
- 147行目: `name="<?php echo $this->client->selectedProviderOptName; ?>"`
- 184行目: `for="<?php echo $optname; ?>"`
- 191行目: `name="<?php echo $optname; ?>"`
- 192行目: `id="<?php echo $optname; ?>"`
- 219行目: `for="<?php echo $optname; ?>"`

### 4. $_POST のサニタイズ不足 - `Client.php:181-188`
```php
if (isset($_POST[$this->selectedProviderOptName])) {
    $selected = (string)$_POST[$this->selectedProviderOptName];
    $this->setSelectedProvider($selected);
}
```
**問題**: `wp_unslash()` や `sanitize_text_field()` が使用されていない。

`ServerControlled.php:191-194` も同様の問題あり。

### 5. $_SERVER のサニタイズ不足 - `Client.php:338`
```php
'webServer' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
```

---

## 🟡 重大度: 低

### 6. mysqli_get_server_info の潜在的エラー - `Client.php:320`
```php
$server_info = mysqli_get_server_info($wpdb->dbh);
```
**問題**: `$wpdb->dbh` が mysqli オブジェクトでない場合にエラーになる可能性。

### 7. buildProvidersFromArray の identifier 問題 - `ServerControlled.php:206`
```php
foreach ($providers as $identifier => $provider) {
```
**問題**: 数値インデックス配列が渡された場合、`$identifier` が数値になる。

### 8. trim(null) の警告 - `Client.php:368`
```php
return trim($host);
```
**問題**: PHP 8.1以降では `$host` が null の場合に Deprecation warning が発生。

---

## ⚠️ 設計上の懸念事項

### 9. API呼び出しの無限ループリスク - `ServerControlled.php:75-84`
**問題**: プロバイダー取得が失敗し続けると毎ページロードでAPI呼び出しが発生。
**推奨**: Exponential backoff の実装。

---

## チェックリスト

- [ ] XSS脆弱性の修正 (#1, #2)
- [ ] HTML属性のエスケープ (#3)
- [ ] $_POST サニタイズ (#4)
- [ ] $_SERVER サニタイズ (#5)
- [ ] mysqli_get_server_info のエラーハンドリング (#6)
- [ ] identifier の型チェック (#7)
- [ ] trim() の null 対応 (#8)
- [ ] API呼び出しの backoff 実装 (#9)
