/* =====================================================================
   BOLU — pengaturan.js
   Modal reset password pengguna (admin).
   ===================================================================== */
(function () {
    'use strict';

    document.querySelectorAll('.btn-reset-pass').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.dataset.id;
            var name = btn.dataset.name;
            SKJAmodal('Reset Password — ' + SKJAesc(name),
                '<form method="post" action="pengaturan.php" id="rpForm">'
                + '<input type="hidden" name="csrf" value="' + document.body.dataset.csrf + '">'
                + '<input type="hidden" name="action" value="reset_password">'
                + '<input type="hidden" name="id" value="' + SKJAesc(id) + '">'
                + '<div class="form-group"><label class="form-label">Password Baru <span class="req">*</span></label>'
                + '<input class="form-control" type="password" name="new_password" required minlength="8" autocomplete="new-password">'
                + '<p class="form-hint">Minimal 8 karakter. Disimpan sebagai hash, tidak pernah plaintext.</p></div>'
                + '<div style="display:flex;justify-content:flex-end;gap:10px">'
                + '<button type="button" class="btn btn-outline" id="rpCancel">Batal</button>'
                + '<button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> Reset Password</button>'
                + '</div></form>');
            document.getElementById('rpCancel').addEventListener('click', SKJAmodalClose);
        });
    });
})();
