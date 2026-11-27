# 备份与恢复

## 导出

将配置和数据库备份分别加密保存，限制读取权限。数据库快照包含账号、业务台账、成绩、证书与迁移记录。用 MySQL 8.4 客户端及仅能访问对应库的备份账号。在无迁移和其他 DDL 执行的时段进行备份。

```sh
mysqldump -h 127.0.0.1 -u learning_backup -p \
  --single-transaction --hex-blob --no-tablespaces --set-gtid-purged=OFF \
  learning_cloud > learning-cloud.sql
sha256sum learning-cloud.sql > learning-cloud.sql.sha256
```

备份账号需要读取表和视图等导出对象的权限。容器部署可用 `docker compose exec -T db` 运行镜像内客户端，使用已在容器中安全配置的客户端身份选项，并把输出写到宿主机。

## 恢复演练

1. 核对 SHA-256。在 MySQL 新建独立的 `learning_restore` 库，字符集使用 `utf8mb4`。按需为临时恢复账号授予该库权限。
2. 导入备份，并给临时 Web 与 CLI 注入恢复库的连接配置。

```sh
sha256sum -c learning-cloud.sql.sha256
mysql -h 127.0.0.1 -u learning_restore -p learning_restore < learning-cloud.sql
php bin/learning.php health
```

3. 以备份的应用标签启动临时工作台，抽查租户登录、历史报名、成绩和证书核验。核对记录主键与数量。升级场景在恢复通过后再执行目标版本迁移。
4. 正式恢复前暂停生产流量和后台处理，保留事故发生时的数据库备份，切换至已验证的恢复库并启动相配套的应用版本。用户重新登录后再恢复业务。
