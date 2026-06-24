<div class="grid sm:grid-cols-2 gap-3">
    <div><label class="label text-xs">Label (optional)</label><input name="label" value="{{ old('label', $a?->label ?? '') }}" class="input" placeholder="Home / Office"></div>
    <div><label class="label text-xs">Full name</label><input name="name" value="{{ old('name', $a?->name ?? auth('customer')->user()->name) }}" class="input" required></div>
</div>
<div><label class="label text-xs">Phone</label><input name="phone" value="{{ old('phone', $a?->phone ?? auth('customer')->user()->phone) }}" class="input" required></div>
<div><label class="label text-xs">Full address</label><textarea name="address" rows="2" class="input" required placeholder="House, road, block…">{{ old('address', $a?->address ?? '') }}</textarea></div>
<div class="grid sm:grid-cols-3 gap-3">
    <div><label class="label text-xs">Area</label><input name="area" value="{{ old('area', $a?->area ?? '') }}" class="input"></div>
    <div><label class="label text-xs">City</label><input name="city" value="{{ old('city', $a?->city ?? '') }}" class="input"></div>
    <div><label class="label text-xs">District</label><input name="district" value="{{ old('district', $a?->district ?? '') }}" class="input"></div>
</div>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_inside_dhaka" value="1" @checked(old('is_inside_dhaka', $a?->is_inside_dhaka ?? false))> Inside Dhaka</label>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $a?->is_default ?? false))> Set as default address</label>
