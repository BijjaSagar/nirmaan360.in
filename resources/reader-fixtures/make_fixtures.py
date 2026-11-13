"""Makes the four reader self-check PDFs. Each is one A4 page that is a single picture, stored the
way a scanner or a phone would store it: CCITT Group 4 fax, JBIG2, JPEG 2000 and JPEG (DCT).
The page content is invented. Needs Pillow with libtiff and OpenJPEG."""
import io, struct, sys, zlib
from PIL import Image, ImageDraw, ImageFont

OUT = sys.argv[1]
W, H = 832, 1176          # about 100 dots an inch on A4; the width is a multiple of 8


def page_picture():
    """An invented estimate page: a title, a ruled table and rows of made-up figures."""
    image = Image.new('L', (W, H), 255)
    draw = ImageDraw.Draw(image)
    try:
        font = ImageFont.load_default(size=18)
        small = ImageFont.load_default(size=14)
    except TypeError:
        font = small = ImageFont.load_default()
    draw.text((60, 50), 'READER SELF-CHECK PAGE', fill=0, font=font)
    draw.text((60, 80), 'Invented estimate for a test. Not a real document.', fill=0, font=small)
    left, top, right, row = 60, 130, W - 60, 34
    columns = [left, left + 60, left + 380, left + 460, left + 560, right]
    rows = 26
    for index in range(rows + 1):
        y = top + index * row
        draw.line((left, y, right, y), fill=0, width=2 if index in (0, 1, rows) else 1)
    for x in columns:
        draw.line((x, top, x, top + rows * row), fill=0, width=2)
    for x, label in zip(columns, ['No', 'Description', 'Unit', 'Qty', 'Rate']):
        draw.text((x + 6, top + 8), label, fill=0, font=small)
    items = ['Excavation in soft soil', 'PCC 1:4:8 below footing', 'RCC M25 in footing', 'Brick work in CM 1:6',
             'Plaster 12 mm in CM 1:4', 'Steel reinforcement', 'Shuttering for slab', 'Flooring vitrified tile']
    for index in range(1, rows):
        y = top + index * row + 8
        draw.text((columns[0] + 6, y), str(index), fill=0, font=small)
        draw.text((columns[1] + 6, y), items[index % len(items)], fill=0, font=small)
        draw.text((columns[2] + 6, y), ['cum', 'sqm', 'kg', 'nos'][index % 4], fill=0, font=small)
        draw.text((columns[3] + 6, y), f'{(index * 37) % 400 + 5}.{index % 10}00', fill=0, font=small)
        draw.text((columns[4] + 6, y), f'{(index * 911) % 9000 + 100}.00', fill=0, font=small)
    draw.rectangle((left, H - 110, left + 220, H - 60), fill=0)          # a solid block, so a blank render cannot pass
    draw.text((left + 240, H - 95), 'TEST BLOCK', fill=0, font=font)
    return image


def pdf(image_dict: bytes, data: bytes) -> bytes:
    content = b'q 595 0 0 842 0 0 cm /Im0 Do Q'
    objects = [
        b'<< /Type /Catalog /Pages 2 0 R >>',
        b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
        b'<< /Type /XObject /Subtype /Image /Width %d /Height %d %s /Length %d >>\nstream\n' % (W, H, image_dict, len(data)) + data + b'\nendstream',
        b'<< /Length %d >>\nstream\n' % len(content) + content + b'\nendstream',
    ]
    out = io.BytesIO()
    out.write(b'%PDF-1.7\n%\xe2\xe3\xcf\xd3\n')
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(out.tell())
        out.write(b'%d 0 obj\n' % number + body + b'\nendobj\n')
    xref = out.tell()
    out.write(b'xref\n0 %d\n' % (len(objects) + 1))
    out.write(b'0000000000 65535 f \n')
    for offset in offsets:
        out.write(b'%010d 00000 n \n' % offset)
    out.write(b'trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n' % (len(objects) + 1, xref))
    return out.getvalue()


def group4(bilevel: Image.Image):
    """The fax data of a one-bit picture, taken out of a TIFF that libtiff wrote as one strip."""
    buffer = io.BytesIO()
    bilevel.save(buffer, format='TIFF', compression='group4', tiffinfo={278: H})
    tiff = Image.open(io.BytesIO(buffer.getvalue()))
    tags = tiff.tag_v2
    offsets, counts = tags[273], tags[279]
    assert len(offsets) == 1, 'expected one strip'
    assert tags.get(266, 1) == 1, 'expected most significant bit first'
    raw = buffer.getvalue()[offsets[0]:offsets[0] + counts[0]]
    return raw, tags[262]          # photometric: 0 means a white run is written for 0 bits


# The arithmetic coder JBIG2 and JPEG 2000 share (ITU-T T.88 Annex E).
QE = [(0x5601, 1, 1, 1), (0x3401, 2, 6, 0), (0x1801, 3, 9, 0), (0x0AC1, 4, 12, 0), (0x0521, 5, 29, 0), (0x0221, 38, 33, 0),
      (0x5601, 7, 6, 1), (0x5401, 8, 14, 0), (0x4801, 9, 14, 0), (0x3801, 10, 14, 0), (0x3001, 11, 17, 0), (0x2401, 12, 18, 0),
      (0x1C01, 13, 20, 0), (0x1601, 29, 21, 0), (0x5601, 15, 14, 1), (0x5401, 16, 14, 0), (0x5101, 17, 15, 0), (0x4801, 18, 16, 0),
      (0x3801, 19, 17, 0), (0x3401, 20, 18, 0), (0x3001, 21, 19, 0), (0x2801, 22, 19, 0), (0x2401, 23, 20, 0), (0x2201, 24, 21, 0),
      (0x1C01, 25, 22, 0), (0x1801, 26, 23, 0), (0x1601, 27, 24, 0), (0x1401, 28, 25, 0), (0x1201, 29, 26, 0), (0x1101, 30, 27, 0),
      (0x0AC1, 31, 28, 0), (0x09C1, 32, 29, 0), (0x08A1, 33, 30, 0), (0x0521, 34, 31, 0), (0x0441, 35, 32, 0), (0x02A1, 36, 33, 0),
      (0x0221, 37, 34, 0), (0x0141, 38, 35, 0), (0x0111, 39, 36, 0), (0x0085, 40, 37, 0), (0x0049, 41, 38, 0), (0x0025, 42, 39, 0),
      (0x0015, 43, 40, 0), (0x0009, 44, 41, 0), (0x0005, 45, 42, 0), (0x0001, 45, 43, 0), (0x5601, 46, 46, 0)]


class MqEncoder:
    def __init__(self):
        self.a, self.c, self.ct = 0x8000, 0, 12
        self.out = bytearray([0])           # out[0] stands for the byte before the first; dropped at the end
        self.index, self.mps = {}, {}

    def encode(self, context, bit):
        i = self.index.get(context, 0)
        mps = self.mps.get(context, 0)
        qe, nmps, nlps, switch = QE[i]
        self.a -= qe
        if bit == mps:
            if self.a & 0x8000:
                self.c += qe
                return
            if self.a < qe:
                self.a = qe
            else:
                self.c += qe
            self.index[context] = nmps
        else:
            if self.a < qe:
                self.c += qe
            else:
                self.a = qe
            if switch:
                self.mps[context] = 1 - mps
            self.index[context] = nlps
        while True:
            self.a = (self.a << 1) & 0xFFFF
            self.c <<= 1
            self.ct -= 1
            if self.ct == 0:
                self.byte_out()
            if self.a & 0x8000:
                break

    def byte_out(self):
        if self.out[-1] == 0xFF:
            self.out.append((self.c >> 20) & 0xFF)
            self.c &= 0xFFFFF
            self.ct = 7
        elif self.c < 0x8000000:
            self.out.append((self.c >> 19) & 0xFF)
            self.c &= 0x7FFFF
            self.ct = 8
        else:
            self.out[-1] += 1
            if self.out[-1] == 0xFF:
                self.c &= 0x7FFFFFF
                self.out.append((self.c >> 20) & 0xFF)
                self.c &= 0xFFFFF
                self.ct = 7
            else:
                self.out.append((self.c >> 19) & 0xFF)
                self.c &= 0x7FFFF
                self.ct = 8

    def flush(self) -> bytes:
        temp = self.c + self.a
        self.c |= 0xFFFF
        if self.c >= temp:
            self.c -= 0x8000
        self.c <<= self.ct
        self.byte_out()
        self.c <<= self.ct
        self.byte_out()
        if self.out[-1] != 0xFF:
            self.out.append(0xFF)
        self.out.append(0xAC)
        return bytes(self.out[1:])


def jbig2(bilevel: Image.Image) -> bytes:
    """One page as an embedded JBIG2 stream: a page information segment and one generic region,
    arithmetic coded with template 0 and the nominal adaptive pixels, which is what scanners write."""
    pixels = bilevel.load()
    black = [[1 if pixels[x, y] == 0 else 0 for x in range(W)] for y in range(H)]
    coder = MqEncoder()
    empty = [0] * W

    def at(row, x):
        return row[x] if 0 <= x < W else 0

    for y in range(H):
        row = black[y]
        up1 = black[y - 1] if y >= 1 else empty
        up2 = black[y - 2] if y >= 2 else empty
        for x in range(W):
            context = 0
            for value in (at(up2, x - 1), at(up2, x), at(up2, x + 1),
                          at(up1, x - 2), at(up1, x - 1), at(up1, x), at(up1, x + 1), at(up1, x + 2),
                          at(row, x - 4), at(row, x - 3), at(row, x - 2), at(row, x - 1),
                          at(up1, x + 3), at(up1, x - 3), at(up2, x + 2), at(up2, x - 2)):     # A1 to A4
                context = (context << 1) | value
            coder.encode(context, row[x])
    coded = coder.flush()

    def segment(number, kind, data):
        return struct.pack('>IBBBI', number, kind, 0, 1, len(data)) + data      # no referred segments, page 1

    page_info = struct.pack('>IIIIBH', W, H, 0, 0, 0, 0)
    region = struct.pack('>IIIIB', W, H, 0, 0, 0) + bytes([0x00]) + bytes([3, 0xFF, 0xFD, 0xFF, 2, 0xFE, 0xFE, 0xFE]) + coded
    return segment(0, 48, page_info) + segment(1, 38, region)


picture = page_picture()
bilevel = picture.point(lambda value: 255 if value > 128 else 0).convert('1')

# Fax data is written for the ink: Pillow stores white as 1 bits, so the picture is turned over first
# and the paper becomes the white runs, as on a scanner.
fax, photometric = group4(bilevel.convert('L').point(lambda value: 255 - value).convert('1'))
black_is_1 = b'false' if photometric == 1 else b'true'
open(f'{OUT}/ccitt-g4.pdf', 'wb').write(pdf(
    b'/ColorSpace /DeviceGray /BitsPerComponent 1 /Filter /CCITTFaxDecode /DecodeParms << /K -1 /Columns %d /Rows %d /BlackIs1 %s >>' % (W, H, black_is_1), fax))

open(f'{OUT}/jbig2.pdf', 'wb').write(pdf(b'/ColorSpace /DeviceGray /BitsPerComponent 1 /Filter /JBIG2Decode', jbig2(bilevel)))

buffer = io.BytesIO()
picture.save(buffer, format='JPEG2000', quality_mode='rates', quality_layers=[12])
open(f'{OUT}/jpeg2000.pdf', 'wb').write(pdf(b'/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /JPXDecode', buffer.getvalue()))

buffer = io.BytesIO()
picture.save(buffer, format='JPEG', quality=70)
open(f'{OUT}/dct.pdf', 'wb').write(pdf(b'/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /DCTDecode', buffer.getvalue()))
print('photometric', photometric)
