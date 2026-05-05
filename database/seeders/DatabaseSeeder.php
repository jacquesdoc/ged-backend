<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Créer les permissions ─────────────────────────────────────────
        $permissions = [
            'view-documents',   'create-documents',
            'edit-documents',   'delete-documents',
            'download-documents', 'share-documents',
            'archive-documents',
            'view-folders',     'create-folders',
            'edit-folders',     'delete-folders',
            'view-workflows',   'create-workflows',
            'approve-workflows',
            'view-users',       'create-users',
            'edit-users',       'delete-users',
            'view-audit-logs',  'export-audit-logs',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // ── Créer les rôles ───────────────────────────────────────────────
        $adminRole  = Role::firstOrCreate(['name' => 'admin']);
        $editorRole = Role::firstOrCreate(['name' => 'editor']);
        $readerRole = Role::firstOrCreate(['name' => 'reader']);

        // Admin → toutes les permissions
        $adminRole->syncPermissions(Permission::all());

        // Éditeur → permissions limitées
        $editorRole->syncPermissions([
            'view-documents',   'create-documents',
            'edit-documents',   'download-documents',
            'share-documents',  'view-folders',
            'create-folders',   'edit-folders',
            'view-workflows',   'create-workflows',
        ]);

        // Lecteur → lecture seule
        $readerRole->syncPermissions([
            'view-documents',   'download-documents',
            'view-folders',     'view-workflows',
        ]);

        // ── Créer les utilisateurs de test ────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'admin@ged.ci'],
            [
                'name'     => 'Administrateur GED',
                'password' => Hash::make('Admin@2024!'),
            ]
        );
        $admin->assignRole('admin');

        $editor = User::firstOrCreate(
            ['email' => 'editeur@ged.ci'],
            [
                'name'     => 'Jean Kouassi',
                'password' => Hash::make('Editor@2024!'),
            ]
        );
        $editor->assignRole('editor');

        $reader = User::firstOrCreate(
            ['email' => 'lecteur@ged.ci'],
            [
                'name'     => 'Marie Traoré',
                'password' => Hash::make('Reader@2024!'),
            ]
        );
        $reader->assignRole('reader');

        $this->command->info('✅ Rôles, permissions et utilisateurs créés !');
        $this->command->info('');
        $this->command->info('Comptes de test :');
        $this->command->info('Admin   → admin@ged.ci   / Admin@2024!');
        $this->command->info('Éditeur → editeur@ged.ci / Editor@2024!');
        $this->command->info('Lecteur → lecteur@ged.ci / Reader@2024!');
    }
}